<?php

/**
 * Zula Framework Model (Article)
 *
 * @patches submit all patches to patches@tangocms.org
 *
 * @author Alex Cartwright
 * @copyright Copyright (C) 2007, 2008, 2009 Alex Cartwright
 * @license http://www.gnu.org/licenses/old-licenses/gpl-2.0.html GNU/GPL 2
 * @package TangoCMS_Article
 */

	class Article_model extends Zula_ModelBase {

		const
				/**
					* Constants used when cleaning a title
					*/
				_TYPE_ARTICLE	= 1,
				_TYPE_CATEGORY	= 2;

		/**
		 * Stores the article count (found rows without limit for self::getAllArticles())
		 * @var int|null
		 */
		protected $articleCount = null;

		/**
		 * Creates a unique 'clean' title to be used for a
		 * article title.
		 *
		 * @param string $title
		 * @return string
		 */
		protected function cleanArticleTitle( $title ) {
			return $this->cleanTitle( $title, self::_TYPE_ARTICLE );
		}

		/**
		 * Creates a unique 'clean' title to be used for a
		 * category title.
		 *
		 * @param string $title
		 * @return string
		 */
		protected function cleanCategoryTitle( $title ) {
			return $this->cleanTitle( $title, self::_TYPE_CATEGORY );
		}

		/**
		 * Cleans and creates a unique title to be used for an article or category title
		 *
		 * @param string $title
		 * @param int $type
		 * @return string
		 */
		protected function cleanTitle( $title, $type=self::_TYPE_ARTICLE ) {
			$title = zula_clean( $title );
			if ( !trim( $title ) ) {
				$title = 'id-';
			}
			$table = ($type & self::_TYPE_ARTICLE) ? 'mod_articles' : 'mod_article_cats';
			$result = $this->_sql->prepare( 'SELECT clean_title FROM {SQL_PREFIX}'.$table.' WHERE clean_title LIKE :title' );
			$result->execute( array(':title' => $title.'%') );
			// Re-build the title if need by by adding a int on the end
			$cleanTitles = $result->fetchAll( PDO::FETCH_COLUMN );
			if ( !empty( $cleanTitles ) ) {
				$i = 1;
				while( in_array( $title.$i, $cleanTitles ) ) {
					++$i;
				}
				$title .= $i;
			}
			return $title;
		}
		
		/**
		 * Gets all articles from the database, or a subset of the result. ACL
		 * permissions can be checked on the parent category if needed.
		 *
		 * @param int $limit
		 * @param int $offset
		 * @param int|bool $cid
		 * @param bool $unpublished
		 * @param bool $aclCheck
		 * @return array
		 */
		public function getAllArticles( $limit=0, $offset=0, $cid=false, $unpublished=false, $aclCheck=true ) {
			$statement = 'SELECT SQL_CALC_FOUND_ROWS * FROM {SQL_PREFIX}mod_articles';
			$params = array();
			if ( $cid ) {
				$statement .= ' WHERE cat_id = :cid';
				$params[':cid'] = abs( $cid );
			}
			if ( $unpublished == false ) {
				$statement .= ($cid ? ' AND' : ' WHERE').' published = 1';
			}
			$statement .= ' ORDER BY published ASC, `date` DESC';
			if ( $limit != 0 || $offset != 0 ) {
				// Limit the result set.
				if ( $limit > 0 ) {
					$statement .= ' LIMIT :limit';
					$params[':limit'] = $limit;
				} else if ( $limit == 0 && $offset > 0 ) {
					$statement .= ' LIMIT 1000000';
				}
				if ( $offset > 0 ) {
					$statement .= ' OFFSET :offset';
					$params[':offset'] = $offset;
				}
				// Prepare and execute query
				$pdoSt = $this->_sql->prepare( $statement );
				foreach( $params as $ident=>$val ) {
					$pdoSt->bindValue( $ident, (int) $val, PDO::PARAM_INT );
				}
				$pdoSt->execute();
			} else {
				if ( $unpublished ) {
					$cacheKey = $cid ? 'articles_c'.$cid : 'articles'; # Used later on as well
					$articles = $this->_cache->get( $cacheKey );
				} else {
					$cacheKey = null;
					$articles = false;
				}
				if ( $articles == false ) {
					$pdoSt = $this->_sql->query( $statement );
				} else {
					$this->articleCount = count( $articles );
				}
			}
			if ( isset( $pdoSt ) ) {
				$articles = array();
				foreach( $pdoSt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
					$articles[ $row['id'] ] = $row;
				}
				$pdoSt->closeCursor();
				$query = $this->_sql->query( 'SELECT FOUND_ROWS()' );
				$this->articleCount = $query->fetch( PDO::FETCH_COLUMN );
				$query->closeCursor();
				if ( isset( $cacheKey ) ) {
					$this->_cache->add( $cacheKey, $articles );
				}
			}
			if ( $aclCheck ) {
				foreach( $articles as $tmpArticle) {
					$resource = 'article-cat-'.$tmpArticle['cat_id'];
					if ( !$this->_acl->resourceExists( $resource ) || !$this->_acl->check( $resource ) ) {
						unset( $articles[ $tmpArticle['id'] ] );
						--$this->articleCount;
					}
				}
			} 
			return $articles;			
		}

		/**
		 * Gets the number of articles which would have been returned if
		 * Article_Model::getAllArticles() had no limit/offset args
		 *
		 * @return int|null
		 */
		public function getCount() {
			$count = $this->articleCount;
			$this->articleCount = null;
			return $count;
		}

		/**
		 * Counts how many articles there are for a specified category
		 *
		 * @param int $cid
		 * @param bool $unpublished
		 * @return int
		 */
		public function countArticles( $cid, $unpublished=false ) {
			$query = 'SELECT COUNT(id) FROM {SQL_PREFIX}mod_articles WHERE cat_id = '.(int) $cid;
			if ( $unpublished == false ) {
				$query .= ' AND published = 1';
			}
			return $this->_sql->query( $query )->fetch( PDO::FETCH_COLUMN );
		}

		/**
		 * Gets details for every category that exists. If set to, ACL permissions
		 * will be checked for each category
		 *
		 * @param bool $aclCheck
		 * @return array
		 */
		public function getAllCategories( $aclCheck=true ) {
			if ( ($categories = $this->_cache->get('article_categories')) == false ) {
				$categories = array();
				foreach( $this->_sql->query( 'SELECT * FROM {SQL_PREFIX}mod_article_cats', PDO::FETCH_ASSOC ) as $cat ) {
					$categories[ $cat['id'] ] = $cat;
				}
				$this->_cache->add( 'article_categories', $categories );
			}
			if ( $aclCheck ) {
				foreach( $categories as $cat ) {
					$resource = 'article-cat-'.$cat['id'];
					if ( !$this->_acl->resourceExists( $resource ) || !$this->_acl->check( $resource ) ) {
						unset( $categories[ $cat['id'] ] );
					}
				}
			}
			return $categories;
		}

		/**
		 * Checks if a category exists by ID or clean title
		 *
		 * @param int|string $cat
		 * @param bool $byId
		 * @return bool
		 */
		public function categoryExists( $cat, $byId=true ) {
			try {
				$this->getCategory( $cat, $byId );
				return true;
			} catch ( Exception $e ) {
				return false;
			}
		}

		/**
		 * Gets details for a category by ID or clean title
		 *
		 * @param int|string $cat
		 * @param bool $byId
		 * @return array
		 */
		public function getCategory( $cat, $byId=true ) {
			$col = $byId ? 'id' : 'clean_title';
			$pdoSt = $this->_sql->prepare( 'SELECT * FROM {SQL_PREFIX}mod_article_cats WHERE '.$col.' = ?' );
			$pdoSt->execute( array($cat) );
			$category = $pdoSt->fetch( PDO::FETCH_ASSOC );
			$pdoSt->closeCursor();
			if ( $category ) {
				return $category;
			} else {
				throw new Article_CatNoExist( $cat );
			}
		}

		/**
		 * Checks if an article exists by ID or clean_title
		 *
		 * @param int|string $article
		 * @return bool
		 */
		public function articleExists( $article, $byId=true ) {
			try {
				$this->getArticle( $article, $byId );
				return true;
			} catch ( Article_NoExist $e ) {
				return false;
			}
		}

		/**
		 * Get details for an article by ID or clean_title
		 *
		 * @param int|string $article
		 * @param bool $byId
		 * @return array
		 */
		public function getArticle( $article, $byId=true ) {
			$col = $byId ? 'id' : 'clean_title';
			$pdoSt = $this->_sql->prepare( 'SELECT * FROM {SQL_PREFIX}mod_articles WHERE '.$col.' = ?' );
			$pdoSt->execute( array($article) );
			$article = $pdoSt->fetch( PDO::FETCH_ASSOC );
			$pdoSt->closeCursor();
			if ( $article ) {
				return $article;
			} else {
				throw new Article_NoExist( $article );
			}
		}

		/**
		 * Gets every part for the specified article (by ID only). If set to,
		 * the body of the article can be omited to reduce data sent.
		 *
		 * @param int $aid
		 * @param bool $withBody
		 * @return array
		 */
		public function getArticleParts( $aid, $withBody=true ) {
			$article = $this->getArticle( $aid );
			$cols = $withBody ? '*' : 'id, article_id, title, `order`';
			$query = $this->_sql->query( 'SELECT '.$cols.' FROM {SQL_PREFIX}mod_article_parts
					   				      WHERE article_id = '.(int) $article['id'].' ORDER BY `order`, id ASC' );
			$parts = array();
			foreach( $query->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
				$parts[ $row['id'] ] = $row;
			}
			return $parts;
		}
		
		/**
		 * Checks if an article part exists by ID
		 *
		 * @param int $pid
		 * @return bool
		 */
		public function partExists( $pid ) {
			try {
				$this->getPart( $pid );
				return true;
			} catch ( Article_PartNoExist $e ) {
				return false;
			}
		}

		/**
		 * Get details for an article part by ID
		 *
		 * @param int $pid
		 * @return array
		 */
		public function getPart( $pid ) {
			$query = $this->_sql->query( 'SELECT * FROM {SQL_PREFIX}mod_article_parts WHERE id = '.(int) $pid );
			$part = $query->fetch( PDO::FETCH_ASSOC );
			$query->closeCursor();
			if ( $part ) {
				return $part;
			} else {
				throw new Article_PartNoExist( $pid );
			}
		}

		/**
		 * Adds a new category
		 *
		 * @param string $title
		 * @param string $description
		 * @return int|bool
		 */
		public function addCategory( $title, $description ) {
			$details = array(
							'title'			=> $title,
							'description'	=> $description,
							'clean_title'	=> $this->cleanCategoryTitle( $title ),
							);
			$pdoSt = $this->_sql->prepare( 'INSERT INTO {SQL_PREFIX}mod_article_cats (title, description, clean_title) VALUES (?, ?, ?)' );
			if ( $pdoSt->execute( array_values($details) ) ) {
				$this->_cache->delete( 'article_categories' );
				$id = $this->_sql->lastInsertId();
				Hooks::notifyAll( 'article_add_category', $id, $details );
				return array(
							'id'			=> $id,
							'clean_title'	=> $details['clean_title'],
							);
			} else {
				return false;
			}
		}

		/**
		 * Updates a category with new details
		 *
		 * @param int $cid
		 * @param string $title
		 * @param string $description
		 * @return bool
		 */
		public function editCategory( $cid, $title, $description ) {
			$category = $this->getCategory( $cid );
			$details = array(
							'title'			=> $title,
							'description'	=> $description,
							'id'			=> $category['id'],
							);
			$pdoSt = $this->_sql->prepare( 'UPDATE {SQL_PREFIX}mod_article_cats SET title = ?, description = ? WHERE id = ?' );
			if ( $pdoSt->execute( array_values($details) ) ) {
				$this->_cache->delete( 'article_categories' );
				Hooks::notifyAll( 'article_edit_category', $category['id'], $details );
			} else {
				return false;
			}
		}

		/**
		 * Deletes a category and all articles under it (including the article parts)
		 *
		 * @param int $cid
		 * @return bool
		 */
		public function deleteCategory( $cid ) {
			$category = $this->getCategory( $cid );
			$query = $this->_sql->query( 'DELETE FROM {SQL_PREFIX}mod_article_cats WHERE id = '.(int) $category['id'] );
			if ( $query->rowCount() ) {
				$query->closeCursor();
				$this->_cache->delete( array('article_categories', 'articles_c'.$category['id'], 'articles') );
				$this->_acl->deleteResource( 'article-cat-'.$category['id'] );
				// Remove all articles and parts
				$query = $this->_sql->query( 'DELETE article, part
											  FROM {SQL_PREFIX}mod_articles AS article
												LEFT JOIN {SQL_PREFIX}mod_article_parts AS part ON part.article_id = article.id
											  WHERE article.cat_id = '.(int) $category['id'].' AND part.id IS NOT NULL' );
				Hooks::notifyAll( 'article_delete_category', $category['id'], $category );
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Adds a new article, and one single article part to go with t.
		 *
		 * @param int $cid
		 * @param string $title
		 * @param string $partBody
		 * @param string $partTitle
		 * @param bool $published
		 * @return string|bool
		 */
		public function addArticle( $cid, $title, $partBody, $partTitle='', $published=true ) {
			$category = $this->getCategory( $cid );
			$details = array(
							'cat_id'		=> $category['id'],
							'title'			=> $title,
							'clean_title'	=> $this->cleanArticleTitle( $title ),
							'part_body'		=> $partBody,
							'part_title'	=> $partTitle,
							'published'		=> (int) $published,
							'author'			=> $this->_session->getUserId(),
							);
			$pdoSt = $this->_sql->prepare( 'INSERT INTO {SQL_PREFIX}mod_articles (cat_id, title, clean_title, `date`, published, author)
											VALUES(?, ?, ?, NOW(), ?, ?)' );
			$result = $pdoSt->execute( array(
											$details['cat_id'], $details['title'], $details['clean_title'],
											$details['published'], $details['author']
											));
			if ( $result ) {
				$this->_cache->delete( 'article_c'.$category['id'] );
				$articleId = $this->_sql->lastInsertId();
				$this->addPart( $articleId, $details['part_body'], $details['part_title'] );
				Hooks::notifyAll( 'article_add', $articleId, $details, $category );
				return array(
							'id'			=> $articleId,
							'clean_title'	=> $details['clean_title'],
							);
			} else {
				return false;
			}
		}

		/**
		 * Edits details for an existing article
		 *
		 * @param int $aid
		 * @param string $title
		 * @param bool $published
		 * @param int $cid
		 * @return bool
		 */
		public function editArticle( $aid, $title, $published=true, $cid=null ) {
			$article = $this->getArticle( $aid );
			$details = array_merge( $article, array(
												'title'		=> $title,
												'published'	=> (int) $published,
												'cid'		=> $cid
												)
								  );
			$date = (!$article['published'] && $published) ? date('Y-m-d H:i:s') : $article['date'];
			$pdoSt = $this->_sql->prepare( 'UPDATE {SQL_PREFIX}mod_articles SET cat_id = ?, title = ?, published = ?, `date` = ?
											WHERE id = ?' );
			if ( $pdoSt->execute( array($details['cid'], $details['title'], $details['published'], $date, $details['id']) ) ) {
				$this->_cache->delete( array('articles', 'articles_c'.$article['cat_id']) );
				Hooks::notifyAll( 'article_edit', $article['id'], $details );
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Deletes an article and all parts in it
		 *
		 * @param int $aid
		 * @return bool
		 */
		public function deleteArticle( $aid ) {
			$article = $this->getArticle( $aid );
			$pdoSt = $this->_sql->prepare( 'DELETE article, part
											FROM {SQL_PREFIX}mod_articles AS article
												INNER JOIN {SQL_PREFIX}mod_article_parts AS part
											WHERE article.id = :aid AND part.article_id = :aid' );
			$pdoSt->execute( array(':aid' => $article['id']) );
			$pdoSt->closeCursor();
			if ( $pdoSt->rowCount() > 0 ) {
				$this->_cache->delete( array('articles', 'articles_c'.$article['cat_id']) );
				Hooks::notifyAll( 'article_delete', $article['id'], $article );
				return true;
			} else {
				return false;
			}
		}

		/**
		 * Adds a new article part to an existing article and returns its ID
		 *
		 * @param int $aid
		 * @param string $body
		 * @param string $title
		 * @param int $order
		 * @return int|bool
		 */
		public function addPart( $aid, $body, $title='', $order=1 ) {
			$article = $this->getArticle( $aid );
			$editor = new Editor( $body );
			$details = array(
							'article_id'	=> $article['id'],
							'title'			=> $title,
							'body'			=> $editor->preParse(),
							'order'			=> abs( $order ),
							);
			$pdoSt = $this->_sql->prepare( 'INSERT INTO {SQL_PREFIX}mod_article_parts (article_id, title, body, `order`)
											VALUES(?, ?, ?, ?)' );
			if ( $pdoSt->execute( array_values($details) ) ) {
				$id = $this->_sql->lastInsertId();
				Hooks::notifyAll( 'article_add_part', $id, $details );
				return $id;
			} else {
				return false;
			}
		}

		/**
		 * Edits an existing article part with new details
		 *
		 * @param int $pid
		 * @param string $title
		 * @param string $body
		 * @param int $order
		 * @return bool
		 */
		public function editPart( $pid, $title, $body, $order ) {
			$part = $this->getPart( $pid );
			$editor = new Editor( $body );
			$details = array(
							'id'			=> $part['id'],
							'article_id'	=> $part['article_id'],
							'title'			=> $title,
							'body'			=> $editor->preParse(),
							'order'			=> abs( $order ),
							);
			$pdoSt = $this->_sql->prepare( 'UPDATE {SQL_PREFIX}mod_article_parts SET title = ?, body = ?, `order` = ?
											WHERE id = ?' );

			if ( $pdoSt->execute( array($details['title'], $details['body'], $details['order'], $details['id']) ) ) {
				Hooks::notifyAll( 'article_edit_part', $part['id'], $details );
			} else {
				return false;
			}
		}

		/**
		 * Delete an article part of an article if it exists
		 *
		 * @param int $pid
		 * @return bool
		 */
		public function deletePart( $pid ) {
			$part = $this->getPart( $pid );
			$query = $this->_sql->query( 'DELETE FROM {SQL_PREFIX}mod_article_parts WHERE id = '.(int) $part['id'] );
			$query->closeCursor();
			if ( $query->rowCount() > 0 ) {
				Hooks::notifyAll( 'article_delete_part', $part['id'], $part );
				return true;
			} else {
				return false;
			}
		}

	}

?>
