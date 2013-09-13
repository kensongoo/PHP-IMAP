<?php

	/**
	 * IMAP is a library that make it easy to connect and work on 
	 * IMAP email account
	 *
	 * @author Kenson Goo (kensongoo@gmail.com)
	 * @version 0.1
	 * 
	 */
	class IMAP
	{

		const DEBUG = false;

		private $mailbox = array();
		private $_resource;
		private $errors = array();
		private $emails = array();

		/**
		 * @param array $mailbox the mailbox settings
		 */
		public function __construct($mailbox = array())
		{
			// make sure PHP-Imap module is installed
			if (!function_exists('imap_open')) {
				throw new Exception('PHP IMAP module is not installed. Please install the module first to use this library');
			}

			if (!empty($mailbox)) {
				$this->mailbox = $mailbox;
			}
		}

		/**
		 * Connect to the mailbox
		 * 
		 * @param array $mailbox the mailbox paramters
		 * @return boolean true if connected successfully, false otherwise
		 */
		public function connect($mailbox = array())
		{
			if (!empty($mailbox)) {
				$this->mailbox = $mailbox;
			}

			// required information
			foreach (array('username', 'password', 'mailbox_connection', 'mailbox_type', 'port') as $row) {
				if (!isset($this->mailbox[$row]) || empty($this->mailbox[$row])) {
					throw new Exception("$row is required, but not found");
				}
			}

			$mailbox = (object) $this->mailbox;
			$connection_string = sprintf('{%s:%s/%s%s%s}%s', 
					                      $mailbox->mailbox_connection,
					                      $mailbox->port, strtolower($mailbox->mailbox_type),
                                          (isset($mailbox->is_ssl) && ($mailbox->is_ssl == true)) ? '/ssl' : '',
                                          (isset($mailbox->is_ssl_self_signed) && ($mailbox->is_ssl == true)) ? '/novalidate-cert' : '/validate-cert',    
                                          (isset($mailbox->default_mailbox) && !empty($mailbox->default_mailbox)) ? $mailbox->default_mailbox : '');

			if (self::DEBUG) {
				echo "Connection String: $connection_string \n\n";
			}

			try {
				$this->_resource = imap_open($connection_string, $mailbox->username, $mailbox->password);
			}
			catch (Exception $ex) {
				throw $ex;
				return false;
			}

			return gettype($this->_resource) == 'resource';
		}

		/**
		 * Read emails
		 * 
		 * @param bool $mark_read mark emails as "Seen" (read) or not
		 * @return array list of emails
		 */
		public function read_emails($uids = array(), $mark_read = true)
		{
			if ( ! empty($uids)) {
				$emails = $this->get_overview($uids);
			}
			else {
				$emails = $this->get_overview();
			}
			
			if (is_array($emails) && ! empty($emails)) {
				foreach($emails as  $key => $email) {
					// read the email
					$email_structure = imap_fetchstructure($this->_resource, $email->get_data('uid'), 1);
					$encoding = 0;
					if (isset($email_structure->parts[1]->encoding) && ! empty($email_structure->parts[1]->encoding)) {						
						$encoding = $email_structure->parts[1]->encoding;
					}
					else if (isset($email_structure->encoding) && ! empty($email_structure->encoding)) {
						$encoding = $email_structure->encoding;
					}	
					
					$email->add_data('body', $this->translate_imap_body(imap_fetchbody ($this->_resource, $email->get_data('uid'), 1, FT_UID), $encoding));
				}
				
				if ($mark_read) {
					$email_uids = array();
					foreach ($emails as $key => $email) {						
						$email_uids[] = $email->get_data('uid');
					}
					
					imap_setflag_full($this->_resource, implode(',', $email_uids), "\\Seen \\Flagged");
				}				
			}			
			
			return $emails;
		}
						
		/**
		 * Search for emails that match the criterias
		 * 
		 * @param array $criterias the search criterias that match the emails
		 * @param bool $mark_read whether mark the emails as read after emails are returned
		 * 
		 * @return array list of emails that match the criterias
		 */
		public function search_emails($criterias, $mark_read = true)
		{
			$temp = array();
			foreach((array)$criterias as $key => $value) {
				$temp[strtoupper(trim($key))] = $value;
			}	
			$criterias = (object)$temp;
			$criteria_string = (isset($criterias->ALL)) ? 'ALL' : NULL;						
			
			// tags that requires string
			foreach( array('BCC', 'BEFORE', 'BODY', 'CC', 'FROM', 'KEYWORD', 'ON', 'SINCE', 'SUBJECT', 'TEXT', 'TO', 'UNKEYWORD') as $tag) {
				if ((isset($criterias->$tag)) && ! empty($criterias->$tag)) {
					$criteria_string .= sprintf(' %s "%s"', $tag, $criterias->$tag);					
				}
			}
			
			// tags that not require string
			foreach( array('ANSWERED', 'DELETED', 'FLAGGED', 'NEW', 'OLD', 'RECENT', 'SEEN', 'UNANSWERED', 'UNDELETED', 'UNFLAGGED', 'UNSEEN') as $tag) {
				if ((isset($criterias->$tag)) && ($criterias->$tag) == true) {
					$criteria_string .= sprintf(' %s', $tag);					
				}
			}
			
			if (self::DEBUG) {
				echo "Search Criterias : $criteria_string \n\n";
			}
			
			$keys = imap_search ($this->_resource, $criteria_string, SE_UID);
			if (empty($keys)) {
				return null;
			}
			
			return $this->read_emails($keys, $mark_read);			
		}

		/**
		 * Private function that decode the IMAP email body based on the encoding type
		 * 
		 * @param string $body the content body of IMAP email
		 * @param int $encoding the encoding type
		 * 
		 * @return string the decoded content body
		 */
		private function translate_imap_body($body, $encoding) {
			switch($encoding) {
				case 0: return $body; break;
				case 1: return $body; break;
				case 2: return quoted_printable_encode ($body); break;
				case 3: return base64_decode($body); break;
				case 4: return quoted_printable_decode($body); break;
				case 5: return $body; break;
			}
		}		
		
		/**
		 * Get the overview of the IMAP account
		 * @param array $uids specify the email UIDS if you just want these emails' overviews
		 * 
		 * @return array list of email overviews
		 */
		public function get_overview($uids = array())
		{			
			if (empty($uids)) { // fetch all
				$quick_overview = imap_check($this->_resource);
				// Fetch an overview for all messages in INBOX
				$result = imap_fetch_overview($this->_resource, "1:{$quick_overview->Nmsgs}", 0);
			}
			else {
				$result = imap_fetch_overview($this->_resource, implode(',', $uids), FT_UID);
			}
										
			$return = array();
			if (is_array($result) && ! empty($result)) {
				foreach ($result as $row) {
					$return[] = new IMAP_Email($this, $row);
				}
			}			
			return $return;
		}

		/**
		 * Get the email count
		 * @return int total email count
		 */
		public function get_email_count()
		{
			return imap_num_msg($this->_resource);
		}

		/**
		 * Mark an email to be removed
		 * 
		 * @param integer $uid the email id (not the sequence id)
		 * @return bool true if email is marked removed successfully, false otherwise.
		 */
		public function remove_email($uid)
		{
			return imap_delete($this->_resource, $uid, FT_UID);
		}

		/**
		 * Delete permanently emails that are marked as delete
		 * 
		 * @return bool true if emails are purged successfully, false otherwise
		 */
		public function purge_deleted_mails()
		{
			return imap_expunge ($this->_resource);
		}
	}
	
	/**
	 * The email object that will hold the content of email	 
	 */
	class IMAP_Email {
		private $IMAP;
		private $data; // object
		
		public function __construct($IMAP, $data)
		{
			$this->IMAP = $IMAP;
			$this->data = (object) $data;
		}
		
		/**
		 * Mark the email itself delete
		 * 
		 * @return boolean true if marked delete successfully, false otherwise
		 */
		public function mark_delete()
		{
			return $this->IMAP->remove_email($this->data->uid);			
		}				
		
		/**
		 * Add data to the object
		 * 
		 * @param string $key the key of the data
		 * @param mixed $value the values to be stored
		 */
		public function add_data($key, $value)
		{
			$this->data = (object)array_merge( (array)$this->data, array($key => $value));
		}
		
		/**
		 * Get the data
		 * 
		 * @param string $key if key is input, return the specified key value, otherwise return all the data 
		 * @return mixed array or value of the matched key
		 */
		public function get_data($key = null)
		{
			if ( ! empty($key)) {
				if (isset($this->data->$key)) {
					return $this->data->$key;
				}			
				return null;
			}
			
			return $this->data;			
		}
	}
?>
