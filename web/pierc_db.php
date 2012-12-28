<?php


class db_class
{
	protected $_conn;
	
	public function __construct( $server, $port, $database, $user, $password, $timezone)
	{
		if ($port) { $port = ":".$port; }
		$this->_conn = mysql_connect( $server.$port, $user, $password );
		if (!$this->_conn){ die ("Could not connect: " + mysql_error() ); }
		mysql_select_db( $database, $this->_conn );
		$this->timezone = $timezone;
	}
	
	public function __destruct( )
	{
		mysql_close( $this->_conn );	
	}
	
}

class pierc_db extends db_class
{
	protected function hashinate( $result )
	{
		$lines = array();
		$counter = 0;
		while( $row = mysql_fetch_assoc($result) )
		{
			if( isset( $row['logTime'] ) )
			{
				date_default_timezone_set('UTC');
				$dt = DateTime::createFromFormat('U', $row['logTime']); //date_create( $row['logTime']);
				$dt->setTimezone( new DateTimeZone($this->timezone));
				$row['logTime'] = $dt->format("Y-m-d H:i:s"); 
			}
			$lines[$counter] = $row;
			$counter++;
		}
		return $lines;
	}
	
	public function get_last_n_lines( $roomID, $n )
	{
		$roomID = mysql_real_escape_string( $roomID );
		$n = (int)$n;
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type, 0 as hidden FROM ofMucConversationLog WHERE roomID = '$roomID' ORDER BY logTime DESC LIMIT $n;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return array_reverse($this->hashinate($results));
	}
	
	public function get_before( $roomID, $id, $n )
	{
		$roomID = mysql_real_escape_string( $roomID );
		$n = (int)$n;
		$id = (int)$id;
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type, 0 as hidden FROM ofMucConversationLog WHERE roomID = '$roomID' AND logTime < $id ORDER BY logTime DESC LIMIT $n;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return $this->hashinate($results);
	}
	
	public function get_after( $roomID, $id, $n )
	{
		$roomID = mysql_real_escape_string( $roomID );
		$n = (int)$n;
		$id = (int)$id;
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type, 0 as hidden FROM ofMucConversationLog WHERE roomID = '$roomID' AND logTime > $id ORDER BY logTime ASC, logTime DESC LIMIT $n;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return $this->hashinate($results);
	}
	
	public function get_lines_between_now_and_id( $roomID, $id)
	{
		$roomID = mysql_real_escape_string( $roomID );
		$id = (int)$id;
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type, 0 as hidden FROM ofMucConversationLog WHERE roomID = '$roomID' AND logTime > $id ORDER BY logTime DESC LIMIT 500";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return array_reverse($this->hashinate($results));
	}
	
	// Returns the number of records in 'roomID' with an ID below $id
	public function get_count( $roomID, $id)
	{
		$roomID = mysql_real_escape_string( $roomID );
		$id = (int)$id;
		$query = "
			SELECT COUNT(*) as count FROM ofMucConversationLog 
				WHERE roomID = '$roomID' 
				AND logTime < $id;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		$res = $this->hashinate($results);
		$count = $res[0]["count"];
		if ( $count < 0 )
		{
			return 0;
		}
		return $count;
	}
	
	public function get_context( $roomID, $id, $n)
	{
		// Let's imagine that we have 800,000 records, divided
		// between two different roomIDs, #hurf and #durf. 
		// we want to select the $n (50) records surrounding
		// id-678809 in #durf. So, first we count the number 
		// of records in # durf that are below id-678809. 
		//
		// Remember: OFFSET is the number of records that MySQL
		// will skip when you do a SELECT statement - 
		// So "SELECT * FROM ofMucConversationLog LIMIT 50 OFFSET 150 will select
		// rows 150-200. 
		//
		// If we used the $count as an $offset, we'd have a conversation
		// _starting_ with id-678809 - but we want to capture the 
		// conversation _surrounding_ id-678809, so we subtract 
		// $n (50)/2, or 25.
 
		$roomID = mysql_real_escape_string($roomID);
		$id = (int)$id;
		$n = (int)$n;
		
		$count = $this->get_count( $roomID, $id );
		
		$offset = $count - (int)($n/2);
		
		if( $offset < 0)
		{
			$offset = 0;
		}
		
		$query = "
			SELECT * 
				FROM (SELECT * FROM ofMucConversationLog 
						WHERE roomID = '$roomID'
						LIMIT $n OFFSET $offset) room_table
				ORDER BY id DESC ;
				";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return array_reverse($this->hashinate($results));
	}
	
	public function get_search_results( $roomID, $search, $n, $offset=0 )
	{
		$search = mysql_real_escape_string($search);
		$roomID = mysql_real_escape_string($roomID);
		$n = (int) $n;
		$offset = (int) $offset;
		
		$searchquery = " WHERE roomID = '$roomID' ";
		$searcharray = split("[ (%20)(%25)(%2520)|]", $search);
		foreach($searcharray as $searchterm )
		{
			$searchquery .= "AND (body LIKE '%".mysql_real_escape_string($searchterm)."%' OR nickname LIKE '%".mysql_real_escape_string($searchterm)."%' ) ";
		}
		
		$n = (int)$n;
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type, 0 as hidden 
				FROM ofMucConversationLog 
			$searchquery ORDER BY logTime DESC LIMIT $n OFFSET $offset;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		$results = array_reverse($this->hashinate($results));
		return $results;
	}
	
	public function get_tag( $roomID, $tag, $n )
	{
		$tag = mysql_real_escape_string($tag);
		$roomID = mysql_real_escape_string($roomID);
		$n = (int)$n;
		
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type, 0 as hidden 
				FROM ofMucConversationLog 
			WHERE body LIKE '".$tag.":%' ORDER BY logTime DESC LIMIT $n;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return array_reverse($this->hashinate($results));
	}
	
	public function get_lastseen( $roomID, $user )
	{
		$user = mysql_real_escape_string($user);
		$roomID = mysql_real_escape_string($roomID);
		
		$query = "
			SELECT logTime 
				FROM ofMucConversationLog 
			WHERE nickname = '".$user."' ORDER BY logTime DESC LIMIT 1;";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return $this->hashinate($results);
	}
	
	public function get_user( $roomID, $user, $n )
	{
		$user = mysql_real_escape_string($user);
		$roomID = mysql_real_escape_string($roomID);
		$n = (int) $n;
		
		$query = "
			SELECT roomID, nickname, logTime, body, sender as type
				FROM ofMucConversationLog 
			WHERE nickname = '".$user."' ORDER BY logTime DESC LIMIT ".$n.";";
		
		$results = mysql_query( $query, $this->_conn);
		if (!$results){ print mysql_error(); return false; }
		if( mysql_num_rows($results) == 0 ) { return false; }
		
		return $this->hashinate($results);
	}
}
?>

