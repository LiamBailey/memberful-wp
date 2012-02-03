<?php

add_action('request', 'memberful_audit_request');
add_action('pre_get_posts', 'memberful_filter_posts');


/**
 * Determines the set of post IDs that the current user cannot access
 *
 * If a page/post requires products a,b then the user will be granted access
 * to the content if they have bought either product a or b
 *
 * TODO: This is calculated on every pageload, maybe use a cache?
 *
 * @return array Map of post ID => post ID
 */
function memberful_user_disallowed_post_ids()
{
	static $ids = NULL;;

	if(is_admin())
		return array();

	if($ids !== NULL)
		return $ids;

	$acl = get_option('memberful_acl', TRUE);

	// The products the user has access to
	$user_products = get_user_meta(wp_get_current_user()->ID, 'memberful_products', TRUE);

	if(empty($user_products))
		$user_products = array();

	$allowed_products    = array_intersect_key($acl, $user_products);
	$restricted_products = array_diff_key($acl, $user_products);

	$allowed_ids    = array();
	$restricted_ids = array();

	foreach($allowed_products as $posts)
	{
		$allowed_ids = array_merge($allowed_ids, $posts);
	}

	foreach($restricted_products as $posts)
	{
		$restricted_ids = array_merge($restricted_ids, $posts);
	}

	// array_merge doesn't preserve keys
	$allowed    = array_unique($allowed_ids);
	$restricted = array_unique($restricted_ids);

	// Remove from the set of restricted posts the posts that the user is 
	// definitely allowed to access
	$union = array_diff($restricted, $allowed);

	return empty($union) ? array() : array_combine($union, $union);
}

/**
 * Prevents user from directly viewing a post
 *
 */
function memberful_audit_request($request_args)
{
	$ids = memberful_user_disallowed_post_ids();

	if( ! empty($request_args['p']))
	{
		if(isset($ids[$post_id]))
		{
			$request_args['error'] = '404';
		}
	}
	// If this isn't the homepage
	elseif ( ! empty($request_args))
	{
		$request_args['post__not_in'] = $ids;
	}

	return $request_args;
}

function memberful_filter_posts($query)
{
	$ids = memberful_user_disallowed_post_ids();

	$query->set('post__not_in', $ids);
}
