<?php
require_once MEMBERFUL_DIR.'/src/user/entity.php';

/**
 * Interface for interacting with a user's products
 *
 */
class Memberful_Wp_User_Subscriptions extends Memberful_Wp_User_Entity { 

	protected function entity_type() {
		return 'subscription';
	}

	protected function format($entity) {
		return array(
			'id' => $entity->id,
			'expires_at' => $entity->expires_at
		);
	}
}
