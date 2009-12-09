<?php

/**
 * Zula Framework Module (groups)
 * --- Allows management of all groups within TangoCMS/Zula
 *
 * @patches submit all patches to patches@tangocms.org
 *
 * @author Alex Cartwright
 * @copyright Copyright (C) 2007, 2008, 2009 Alex Cartwright
 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL 2
 * @package TangoCMS_Groups
 */

	class Groups_controller_index extends Zula_ControllerBase {

		/**
		 * Constructor
		 * Sets common page links
		 *
		 * @return void
		 */
		public function __construct( $moduleDetails, $config, $sector ) {
			parent::__construct( $moduleDetails, $config, $sector );
			$this->setPageLinks( array(
										t('Manage Groups')	=> $this->_router->makeUrl( 'groups' ),
										t('Add Group')		=> $this->_router->makeUrl( 'groups', 'index', 'add' ),
										));
		}

		/**
		 * Displays all of the groups in a way which easily shows
		 * which group inherits from what.
		 *
		 * @return string
		 */
		public function indexSection() {
			$this->_locale->textDomain( $this->textDomain() );
			$this->setTitle( t('Manage Groups') );
			$this->setOutputType( self::_OT_CONFIG );
			if ( !$this->_acl->checkMulti( array('groups_add', 'groups_edit', 'groups_delete') ) ) {
				throw new Module_NoPermission;
			}
			if ( $this->_input->checkToken() ) {
				// Purge or Delete selected groups.
				try {
					$groupIds = $this->_input->post( 'group_ids' );
					if ( $this->_input->has( 'post', 'groups_delete' ) ) {
						$method = 'deleteGroup';
						$msg = t('Deleted selected groups');
					} else if ( $this->_input->has( 'post', 'groups_purge' ) ) {
						$method = 'purgeGroup';
						$msg = t('Purged selected groups');
					} else {
						throw new Module_ControllerNoExist;
					}
					if ( $this->_acl->check( 'groups_delete' ) ) {
						$count = 0;
						foreach( (array) $groupIds as $gid ) {
							try {
								$this->_ugmanager->$method( $gid );
								++$count;
							} catch ( UGManager_InvalidGroup $e ) {
								$this->_event->error( t('You can not purge/delete the "root" or "guest" group') );
							} catch ( UGManager_GroupNoExist $e ) {
							}
						}
						if ( $count > 0 ) {
							$this->_event->success( $msg );
						}
					} else {
						throw new Module_NoPermission;
					}
				} catch ( Input_KeyNoExist $e ) {
					$this->_event->error( t('No groups selected') );
				}
				return zula_redirect( $this->_router->makeUrl( 'groups' ) );
			} else {
				/**
				* Attach on which groups inherit from what, so we can easily
				* generate the interface we are after
				*/
				$groups = array();
				foreach( $this->_model()->getGroups() as $group ) {
					$groups[] = $group;
					$children = $this->_acl->getRoleTree( $group['role_id'], true );
					if ( is_array( $children ) ) {
						array_shift( $children );
						foreach( $children as $child ) {
							try {
								$details = $this->_ugmanager->getGroup( $child['id'], true );
							} catch ( UGManager_GroupNoExist $e ) {
								continue;
							}
							$details['level'] = $child['level'];
							$groups[] = $details;
						}
					}
				}
				// Build and output the main view file
				$view = $this->loadView( 'overview.html' );
				$view->assign( array('groups' => $groups) );
				$view->assignHtml( array('CSRF' => $this->_input->createToken( true )) );
				return $view->getOutput();
			}
		}

		/**
		 * Shows the form for adding a new group, or will handle
		 * the creation of adding the new group.
		 *
		 * @return string
		 */
		public function addSection() {
			$this->_locale->textDomain( $this->textDomain() );
			$this->setTitle( t('Add Group') );
			$this->setOutputType( self::_OT_CONFIG );
			if ( !$this->_acl->check( 'groups_add' ) ) {
				throw new Module_NoPermission;
			}
			// Prepare form validation
			$form = $this->buildForm();
			if ( $form->hasInput() && $form->isValid() ) {
				$fd = $form->getValues( 'group' );
				try {
					$this->_ugmanager->addGroup( $fd['name'], $fd['inherits'] );
					$this->_event->success( sprintf( t('Added group "%s"'), $fd['name'] ) );
					return zula_redirect( $this->_router->makeUrl( 'groups' ) );
				} catch ( UGManager_GroupExists $e ) {
					$this->_event->error( sprintf( t('The group "%s" already exists'), $fd['name'] ) );
				} catch ( UGmanager_InvalidInheritance $e ) {
					$this->_event->error( sprintf( t('The inheritance group "%s" does not exist'), $fd['inherits'] ) );
				}
			}
			return $form->getOutput();
		}

		/**
		 * Displays the form or does the edit of a group
		 *
		 * @return string
		 */
		public function editSection() {
			$this->_locale->textDomain( $this->textDomain() );
			$this->setTitle( t('Edit Group') );
			$this->setOutputType( self::_OT_CONFIG );
			if ( !$this->_acl->check( 'groups_edit' ) ) {
				throw new Module_NoPermission;
			}
			// Get details of the group we're to be editing
			try {
				$group = $this->_ugmanager->getGroup( $this->_router->getArgument( 'id' ) );
				$roleDetails = $this->_acl->getRole( $group['role_id'] );
				if ( $roleDetails['name'] == 'group_root' ) {
					$this->_event->error( t('Sorry, you can not edit the root group') );
				} else {
					// Prepare form validation
					$inherits = $this->_ugmanager->roleGid( $roleDetails['parent_id'] );
					$form = $this->buildForm( $group['name'], $inherits, $group['id'], $group['role_id'] );
					if ( $form->hasInput() && $form->isValid() ) {
						$fd = $form->getValues( 'group' );
						try {
							$this->_ugmanager->editGroup( $group['id'], $fd['name'], $fd['inherits'] );
							$this->_event->success( sprintf( t('Edited group "%s"'), $fd['name'] ) );
							return zula_redirect( $this->_router->makeUrl( 'groups' ) );
						} catch ( UGManager_GroupExists $e ) {
							$this->_event->error( t('A group with the same name already exists') );
						} catch ( UGmanager_InvalidInheritance $e ) {
							$this->_event->error( sprintf( t('The inheritance group "%s" does not exist'), $fd['inherits'] ) );
						}
					}
					return $form->getOutput();					
				}
			} catch ( Router_ArgNoExist $e ) {
				$this->_event->error( t('No group selected') );
			} catch ( UGManager_GroupNoExist $e ) {
				$this->_event->error( t('Group does not exist') );
			} catch ( ACL_RoleNoExist $e ) {
				$this->_event->error( sprintf( t('ACL Resource role "%s" does not exist'), $group['role_id'] ) );
			}
			return zula_redirect( $this->_router->makeUrl( 'groups' ) );
		}

		/**
		 * Builds the form for adding or editing a group
		 *
		 * @param string $name
		 * @param int $inherits
		 * @param int $id
		 * @param int $roleId
		 * @return object
		 */
		protected function buildForm( $name=null, $inherits=null, $id=null, $roleId=null ) {
			$this->_locale->textDomain( $this->textDomain() );
			if ( is_null( $id ) ) {
				$op = 'add';
				$inherits = UGManager::_GUEST_GID;
				$groups = $this->_ugmanager->getAllGroups();
			} else {
				$op = 'edit';
				// Grab all groups that are not a child of the current one
				$invalidGid = array();
				foreach( $this->_acl->getRoleTree( $roleId, true ) as $child ) {
					$invalidGid[] = $child['id'];
				}
				$groups = array();
				foreach( $this->_ugmanager->getAllGroups() as $group ) {
					if ( !in_array( $group['role_id'], $invalidGid ) ) {
						$groups[] = $group;
					}
				}
			}
			$form = new View_Form( 'form.html', 'groups', is_null($id) );
			$form->addElement( 'group/name', $name, t('Group Name'), array(new Validator_Alphanumeric, new Validator_Length(1, 32)) );
			$form->addElement( 'group/inherits', $inherits, t('Inheritance Group'), new Validator_Numeric );
			// Additional config data
			$form->assign( array(
								'OP'		=> $op,
								'ID'		=> $id,
								'GROUPS'	=> $groups,
								));
			return $form;
		}

	}

?>
