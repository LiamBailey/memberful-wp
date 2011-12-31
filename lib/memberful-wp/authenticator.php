<?php

/**
 * Is OAuth authentication enabled?
 *
 * @return boolean
 */
function memberful_wp_oauth_enabled()
{
	return TRUE;
}

class Memberful_Authenticator
{
	/**
	 * Gets the url for the specified action at the member oauth endpoint
	 *
	 * @param string $action Action to access at endpoint
	 * @return string URL
	 */
	static function oauth_member_url($action = '')
	{
		return memberful_url('oauth/'.$action);
	}

	/**
	 * Returns the url of the endpoint that members will be sent to
	 *
	 * @return string
	 */
	static function oauth_auth_url()
	{
		$params = array(
			'response_type' => 'code',
			'client_id'     => get_option('memberful_client_id')
		);

		return add_query_arg($params, self::oauth_member_url());
	}

	/**
	 * @var WP_Error Errors encountered
	 */
	protected $_wp_error = NULL;

	protected function _error($code, $message = NULL)
	{
		if($message === NULL)
		{
			$message = 'Could not authenticate against memberful';
		}

		$message .= '<br/>Please contact site admin';

		return $this->_wp_error = new WP_Error($code, $message);
	}

	/**
	 * Authentication for subscribers is handled by Memberful.
	 * Prevent subscribers from requesting password resets
	 *
	 * @return boolean
	 */
	public function audit_password_reset($allowed, $user_id)
	{
		$user = new WP_User($user_id);

		return $user->has_cap('subscriber') ? FALSE : $allowed;
	}

	/**
	 * Callback for the `authenticate` hook.
	 *
	 * Called in wp-login.php when the login form is rendered, thus it responds
	 * to both GET and POST requests.
	 *
	 * @return WP_User The user to be logged in or NULL if user couldn't be
	 * determined
	 */
	public function init($user, $username, $password)
	{
		// If another authentication system has handled this request
		if($user instanceof WP_User || ! memberful_wp_oauth_enabled())
		{
			return $user;
		}

		// If a username or password has been posted then fallback to normal auth
		//
		// If GET isn't empty (e.g. a redirect_to is supplied) and the page that sent
		// them here hasn't requested memberful authentication then chances are its
		// some kind of admin related operation which a customer won't be able to perform,
		// in which case we should allow them to specify a username/password to
		// login with
		if( ! empty($username) || ! empty($password) || ( ! empty($_GET) && ! isset($_GET['memberful_auth'])))
		{
			return $user;
		}

		// This is the OAuth response
		if(isset($_GET['code']))
		{
			$tokens = $this->get_oauth_tokens($_GET['code']);

			if(is_wp_error($tokens))
				return $tokens;

			$details = $this->get_member_data($tokens->access_token);

			$user = $this->sync_user($details->member, $tokens->refresh_token);

			$this->sync_products($user, $details->products);

			return $user;
		}
		// For some reason we got an error code.
		elseif(isset($_GET['error']))
		{
			return $this->_error(
				'memberful_oauth_error', 
				'An error prevented you from being logged in.('.htmlentities($_GET['error']).')'
			);
		}

		// Send the user to memberful
		wp_redirect(self::oauth_auth_url(), 302);
		exit();
	}

	/**
	 * For some amazingly obvious reason which I don't quite understand, 
	 * wp_authenticate_username_password always overrides any errors generated
	 * by authentication hooks.
	 *
	 * This filter is injected after username_password and will re-set any 
	 * memberful errors.
	 *
	 * @param mixed $user
	 * @return mixed
	 */
	public function relay_errors($user)
	{
		if($user instanceof WP_Error)
		{
			if(in_array($user->get_error_code(), array('empty_username', 'empty_password')))
			{
				return $this->_wp_error;
			}
		}

		return $user;
	}

	/**
	 * Gets the access token and refresh token from an authorization code
	 *
	 * @param string $auth_code The authorization code returned from oauth endpoint
	 * @return StdObject Access token and Refresh token
	 */
	public function get_oauth_tokens($auth_code)
	{
		$params = array(
			'client_id'     => get_option('memberful_client_id'),
			'client_secret' => get_option('memberful_client_secret'),
			'grant_type'    => 'authorization_code',
			'code'          => $auth_code
		);
		$response = wp_remote_post(self::oauth_member_url('token'), array('body' => $params));
		$body = json_decode($response['body']);
		$code = $response['response']['code'];

		if ($code !== 200 OR $body === NULL OR empty($body->access_token))
		{
			return $this->_error(
				'oauth_access_fail', 
				'Could not get access token from Memberful'
			);
		}

		return json_decode($response['body']);
	}

	/**
	 * Gets information about a user from memberful.
	 *
	 * @param string $access_token An access token which can be used to get info
	 * about the member
	 * @return array
	 */
	public function get_member_data($access_token)
	{
		memberful_member_url(MEMBERFUL_JSON);

		$response = wp_remote_get(add_query_arg('access_token', $access_token, $url));

		$body = json_decode($response['body']);

		if($response['response']['code'] !== 200 OR $body === NULL)
		{
			return $this->error('memberful_data_error', 'Could not fetch your data from Memberful.');
		}

		return $body;
	}

	/**
	 * Takes a set of memberful member details and tries to associate it with the
	 * wordpress user account.
	 *
	 * @param StdObject $details       Details about the member
	 * @param string    $refresh_token The member's refresh token for oauth
	 * @return WP_User
	 */
	public function sync_user($member, $refresh_token)
	{
		global $wpdb;

		$query = $wpdb->prepare(
			'SELECT *, (`memberful_member_id` = %d) AS `exact_match` FROM `'.$wpdb->users.'` WHERE `memberful_member_id` = %d OR `user_email` = %s ORDER BY `exact_match` DESC',
			$member->id,
			$member->id,
			$member->email
		);

		$user = $wpdb->get_row($query);

		// User does not exist
		if($user === NULL)
		{
			$data = array(
				'user_pass'     => wp_generate_password(),
				'user_login'    => $member->username,
				'user_nicename' => $member->full_name,
				'user_email'    => $member->email,
				'display_name'  => $member->full_name,
				'nickname'      => $member->full_name,
				'first_name'    => $member->first_name,
				'last_name'     => $member->last_name,
				'show_admin_bar_frontend' => FALSE,
			);

			$user_id = wp_insert_user($data);

			if(is_wp_error($user_id))
			{
				var_dump($user_id);
				die('ERRORR!!!');
				return $user_id;
			}
		}
		else
		{
			// Now sync the two accounts
			$user_id = $user->ID;

			// Mapping of wordpress => memberful keys
			$mapping = array(
				'user_email'    => 'email',
				'user_login'    => 'username',
				'display_name'  => 'full_name',
				'user_nicename' => 'full_name',

			);

			$metamap = array(
				'nickname'      => 'full_name',
				'first_name'    => 'first_name',
				'last_name'     => 'last_name'
			);

			$meta = get_user_meta($user_id, '', true);

			// For some insane reason Wordpress only allows us to do a complete update of values
			// No partial updates allowed.
			$data = (array) $user;

			foreach($mapping as $wp_key => $m_key)
			{
				$data[$wp_key] = $member->$m_key;
			}

			foreach($metamap as $wp_key => $m_key)
			{
				$data[$wp_key] = $member->$m_key;
			}

			wp_insert_user($data);
		}

		$wpdb->query($wpdb->prepare('UPDATE `'.$wpdb->users.'` SET `memberful_refresh_token` = %s, `memberful_member_id` = %d WHERE `ID` = %d', $refresh_token, $member->id, $user_id));
		
		return get_userdata($user_id);
	}

	public function sync_products(WP_User $user, $products)
	{
		$product_ids = array_map(array($this, '_extract_product_id'), $products);

		update_user_meta($user->ID, 'memberful_products', $product_ids);
	}

	protected function _extract_product_id($product_link)
	{
		return (int) $product_link->product_id;
	}
}

$authenticator = new Memberful_Authenticator;

add_filter('authenticate', array($authenticator, 'init'), 10, 3);
add_filter('authenticate', array($authenticator, 'relay_errors'), 50, 3);
add_filter('allow_password_reset', array($authenticator, 'audit_password_reset'), 50, 2);

function memberful_sync_products()
{
	$url = memberful_admin_products(MEMBERFUL_JSON);

	$full_url = add_query_arg('auth_token', get_option('memberful_api_key'), $url);

	$response = wp_remote_get($full_url);

	if(is_wp_error($response))
	{
		var_dump($response, $full_url, $url);
		die();
	}

	if($response['response']['code'] !== 200 OR ! isset($response['body']))
	{
		return new WP_Error('memberful_product_sync_fail', "Couldn't retrieve list of products from memberful");
	}

	$raw_products = json_decode($response['body']);
	$products = array();

	foreach($raw_products as $product)
	{
		$products[$product->id] = array('name' => $product->name, 'for_sale' => $product->for_sale);
	}

	update_option('memberful_products', $products);

	return TRUE;
}
