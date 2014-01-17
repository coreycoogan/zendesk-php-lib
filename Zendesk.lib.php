<?php
/*
Zendesk PHP Library
zendesk.php
(c) 2011 Brian Hartvigsen
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are
met:

	* Redistributions of source code must retain the above copyright
	  notice, this list of conditions and the following disclaimer.
	* Redistributions in binary form must reproduce the above
	  copyright notice, this list of conditions and the following
	  disclaimer in the documentation and/or other materials provided
	  with the distribution.
	* Neither the name of the Zendesk PHP Library nor the names of its
	  contributors may be used to endorse or promote products derived
	  from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

GIT - 29 Oct 2013
 * Start support for Zendesk REST API v2
SVN - 15 May 2011
 * Fixed POST, DELETE, PUT handling as per documentation
SVN - 24 March 2011
 * Better handling of multiple headers (100 Continue) sent to cURL (issue #10)
SVN - 23 March 2011
 * Fixed lack of $ when checking key (issue #9)
SVN - 22 March 2011
 * Fixed handling of XML entities by using <![CDATA[]]> (issue #8)
SVN - 03 March 2011
 * Fixes call for JSON response (issue #7)
SVN - 02 March 2011
 * Added support for UTF-8 (issue #6)
SVN - 22 January 2011
 * Fixed bug with dictionary elements being called arrays
 * Added support for HTTPS (issue #5)
v1.02 - 01 March 2010
 * Fixed bug w/ query params in an array
 * Properly singalize ies to y (entries=>entry)
 * Attempt to support ticket/request updating
v1.01 - 10 May 2009
 * Fixed Issue #1 - Need $ on line 223
v1 - Initial Version
*/

define('ZENDESK_ENTRIES', 'entries');
define('ZENDESK_FORUMS', 'forums');
define('ZENDESK_GROUPS', 'groups');
define('ZENDESK_MACROS', 'macros');
define('ZENDESK_ORGANIZATIONS', 'organizations');
define('ZENDESK_POSTS', 'posts');
define('ZENDESK_REQUESTS', 'requests');
define('ZENDESK_RULES', 'rules');
define('ZENDESK_SEARCH', 'search');
define('ZENDESK_TAGS', 'tags');
define('ZENDESK_TICKETS', 'tickets');
define('ZENDESK_TICKET_FIELDS', 'ticket_fields');
define('ZENDESK_UPLOADS', 'uploads'); // not currently supported!!!
define('ZENDESK_USERS', 'users');
define('ZENDESK_VIEWS', 'views');

// Aliases
define('ZENDESK_ATTACHMENTS', ZENDESK_UPLOADS);

class Zendesk
{
	function Zendesk($account, $username, $password, $isToken=true, $use_curl = true, $use_https = true)
	{
		$this->account = $account;
		$this->secure = $use_https;
        $this->isToken=$isToken;

        $tokenStr = $isToken ? '/token' : '';
        $username .= $tokenStr;

		if (function_exists('curl_init') && $use_curl)
		{
			$this->curl = curl_init();
			curl_setopt($this->curl, CURLOPT_USERPWD, $username . ':' . $password);
			curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, false);
			curl_setopt($this->curl, CURLOPT_HEADER, true);
		}
		else
		{
			$this->username = $username;
			$this->password = $password;
		}
	}
	
	function __destruct()
	{
		if (isset($this->curl)) curl_close($this->curl);
	}
	
	function set_secure($use_https = true)
	{
		$this->secure = $use_https;
	}
	
	private function _request($page, $opts, $method = 'GET')
	{
		$this->result = array('header' => null, 'content' => null);
		
		$url = 'http' . ( $this->secure ? 's' : '' ) . "://{$this->account}.zendesk.com/api/v2/$page";
		if (isset($opts['id']))
			$url .= "/{$opts['id']}";
		$url .= '.json';
		
		if (isset($opts['query']))
		{
			if (is_array($opts['query']))
			{
				$query = '?';
				foreach ($opts['query'] as $key=>$value)
					$query .= urlencode($key).'='.urlencode($value).'&';
				$url .= $query;
			}
			else
				$url .= '?'.$opts['query'];
		}
		
		if (isset($this->curl))
		{
			curl_setopt($this->curl, CURLOPT_URL, $url);
			curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
			// HACK: we could include the Zendesk certificate CA info here, but that's a huge increase in filesize.
			if ($this->secure) curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
			
			$headers = array();
			if (isset($opts['data']))
			{
				$headers = array(
					'Content-Type: application/json; charset=utf-8',
					'Accept: application/json'
				);
				
				curl_setopt($this->curl, CURLOPT_POSTFIELDS, $opts['data']);
			}
			
			if (isset($opts['on-behalf-of']))
				$headers[] = 'X-On-Behalf-Of: ' . $opts['on-behalf-of'];
 
			curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);

			$result = curl_exec($this->curl);
			$info = curl_getinfo($this->curl);
			$header = substr($result,0,$info['header_size']);
			$body = substr($result, $info['header_size']);
			$this->result = array('header' => $header, 'content' => $body, 'code' => $info['http_code']);

		}
		else
		{
			$settings = array(
				'http' => array(
					'method' => $method,
					'header' => 'Authorization: Basic ' . base64_encode("{$this->username}:{$this->password}") . "\r\n",
					'max_redirects' => 1
				)
			);
			
			if (isset($opts['data']))
			{
				$settings['http']['content'] = $opts['data'];
				$settings['http']['header'] .= "Content-type: application/json; charset=utf-8\r\n" .
											   "Accept: application/json\r\n";
			}
			
			if (isset($opts['on-behalf-of']))
				$settings['http']['header'] .= "X-On-Behalf-Of: {$opts['on-behalf-of']}\r\n";
				
			$settings['http']['header'] = substr($settings['http']['header'],0, -2);
			
			$context = stream_context_create($settings);
			$this->result['content'] = @file_get_contents($url, false, $context);
			$this->result['header'] = implode("\n", $http_response_header);
			$this->result['code'] = substr($http_response_header[0], 9, 3);
		}
		return $this->result['code'];
	}
	
	private function _singular($noun)
	{
		if (preg_match('/ies$/i', $noun))
			return preg_replace('/ies$/i', 'y', $noun);
		else return substr($noun, 0, -1);
	}
	
	function get($page, $args = array())
	{
		if (200 == $this->_request($page, $args))
			return $this->result['content'];
		else
			return false;
	}
	
	function create($page, $args)
	{
		// We unset $args['id'] as we never use it in a create.
		if (isset($args['id'])) unset($args['id']);
		if (!isset($args['details'])) trigger_error($page . ' details are required');

		// To open a new ticket as an end-user, the request goes to requests.xml, but the root
		// element is <ticket> this allows that.
		if ($page == ZENDESK_REQUESTS)
			$root = 'ticket';
		else
			$root = $this->_singular($page);
						
		$args['data'] = json_encode(array($root => $args['details']));
        $this->_request($page, $args, 'POST');

		if ($this->result['code'] == 201) {

            $resp = json_decode($this->result['content']);
            if(isset($resp->ticket)){
                return $resp->ticket->id;
            }

//			if (preg_match("!https?://{$this->account}/api/v2/$page/#?(\d+)!i", $this->result['header'], $match))
//				return $match[1];
			// regexp failed, this is not good and shouldn't happen, but I don't want to return false...
			return true;
		}
		return false;
	}
	
	function update($page, $args)
	{
		if (!isset($args['id'])) trigger_error($page . ' id is required');
		if (!isset($args['details'])) trigger_error($page . ' details are required');
		
		if ($page == ZENDESK_REQUESTS || ($page == ZENDESK_TICKETS && isset($args['details']['value'])))
			$args['data'] = json_encode(array('comment' => $args['details']));
		else
			$args['data'] = json_encode(array($this->_singular($page) => $args['details']));
		
		return $this->_request($page, $args, 'PUT') == 200;
	}
	
	function delete($page, $args)
	{
		if (isset($args) && !is_array($args)) $args = array('id' => $args);
		if (!isset($args['id'])) trigger_error($page . ' id is required');
		
		return $this->_request($page, $args, 'DELETE') == 200;
	}
}
