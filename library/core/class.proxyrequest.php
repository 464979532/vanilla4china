<?php if (!defined('APPLICATION')) exit();

/**
 * ProxyRequest handler class
 * 
 * This class abstracts the work of doing external requests.
 * 
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Garden
 * @since 2.0.18
 */

class ProxyRequest {
   
   protected static $ConnectionHandles;
   
   public $MaxReadSize = 4096;
   
   public $RequestHeaders;
   
   public $ResponseHeaders;
   public $ResponseStatus;
   public $ResponseBody;
   
   public $ContentType;
   public $ContentLength;
   public $ConnectionMode;
   
   protected $FileTransfer;
   protected $UseSSL;
   protected $SaveFile;
   
   public $ActionLog;
   
   protected $Options;
   
   public function __construct($Loud = FALSE) {
      self::$ConnectionHandles = array();
      
      $this->Loud = $Loud;
   }
   
   public function CurlHeader(&$Handler, $HeaderString) {
      $Line = explode(':',trim($HeaderString));
      $Key = trim(array_shift($Line));
      $Value = trim(implode(':',$Line));
      $this->ResponseHeaders[$Key] = $Value;
      return strlen($HeaderString);
   }
   
   protected function CurlReceive(&$Handler) {
      $this->ResponseHeaders = array();
      $Response = curl_exec($Handler);
      
      $this->ResponseStatus = curl_getinfo($Handler, CURLINFO_HTTP_CODE);
      $this->ContentType = strtolower(curl_getinfo($Handler, CURLINFO_CONTENT_TYPE));
      $this->ContentLength = (int)curl_getinfo($Handler, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
      
      $RequestHeaderInfo = trim(curl_getinfo($Handler, CURLINFO_HEADER_OUT));
      $RequestHeaderLines = explode("\n",$RequestHeaderInfo);
      $Request = trim(array_shift($RequestHeaderLines));
      $this->RequestHeaders['HTTP'] = $Request;
      // Parse header status line
      foreach ($RequestHeaderLines as $Line) {
         $Line = explode(':',trim($Line));
         $Key = trim(array_shift($Line));
         $Value = trim(implode(':',$Line));
         $this->RequestHeaders[$Key] = $Value;
      }
      $this->Action(" Request Headers: ".print_r($this->RequestHeaders,TRUE));
      $this->Action(" Response Headers: ".print_r($this->ResponseHeaders,TRUE));
      
      if ($Response == FALSE) {
         $Success = FALSE;
         $this->ResponseBody = curl_error($Handler);
         return $this->ResponseBody;
      }
      
      $this->ResponseBody = trim($Response);
      
      if ($this->SaveFile) {
         $Success = file_exists($this->SaveFile);
         $SavedFileResponse = array(
            'Error'     => curl_error($Handler),
            'Success'   => $Success,
            'Size'      => filesize($this->SaveFile),
            'Time'      => curl_getinfo($Handler, CURLINFO_TOTAL_TIME),
            'Speed'     => curl_getinfo($Handler, CURLINFO_SPEED_DOWNLOAD),
            'Type'      => curl_getinfo($Handler, CURLINFO_CONTENT_TYPE),
            'File'      => $this->SaveFile
         );
         $this->ResponseBody = json_encode($SavedFileResponse);
      }
      
      return $this->ResponseBody;
   }
   
   /**
    * Send a request and receive the response
    * 
    * Options:
    *   URL
    *   Host
    *   Method
    *   ConnectTimeout
    *   Timeout
    *   Redirects
    *   Cookies
    *   SaveAs
    *   CloseSession
    *   Redirected
    *   Debug
    *   Simulate
    * 
    * @param type $Options
    * @param array $QueryParams
    * @param array $Files
    * @param type $ExtraHeaders
    * @return type 
    */
   public function Request($Options, $QueryParams = NULL, $Files = NULL, $ExtraHeaders = NULL) {
      
      /*
       * Allow requests that just want to use defaults to provide a string instead
       * of an optionlist.
       */
      
      if (is_string($Options))
         $Options = array('URL' => $Options);

      $Defaults = array(
          'URL'                  => NULL,
          'Host'                 => NULL,
          'Method'               => 'GET',
          'ConnectTimeout'       => 5,
          'Timeout'              => 5,
          'SaveAs'               => NULL,
          'Redirects'            => TRUE,
          'SSLNoVerify'          => FALSE,
          'Cookies'              => TRUE,       // Send cookies?
          'CloseSession'         => TRUE,       // Whether to close the session. Should always do this.
          'Redirected'           => FALSE,      // Flag. Is this a redirected request?
          'Debug'                => FALSE,      // Debug output on?
          'Simulate'             => FALSE       // Don't actually request, just set up
      );
      
      $this->Options = $Options = array_merge($Defaults, $Options);

      $this->ResponseHeaders = array();
      $this->ResponseStatus = "";
      $this->ResponseBody = "";
      $this->ContentLength = 0;
      $this->ContentType = '';
      $this->ConnectionMode = '';
      $this->ActionLog = array();
      
      if (!is_array($QueryParams)) $QueryParams = array();
      if (!is_array($Files)) $Files = array();
      if (!is_array($ExtraHeaders)) $ExtraHeaders = array();

      // Get the URL
      $RelativeURL = GetValue('URL', $Options, NULL);
      if (is_null($RelativeURL))
         $RelativeURL = GetValue('Url', $Options, NULL);
      
      if (is_null($RelativeURL))
         throw new Exception("No URL provided");
      
      $RequestMethod = GetValue('Method', $Options);
      $ForceHost = GetValue('Host', $Options);
      $FollowRedirects = GetValue('Redirects', $Options);
      $ConnectTimeout = GetValue('ConnectTimeout', $Options);
      $Timeout = GetValue('Timeout', $Options);
      $SaveAs = GetValue('SaveAs', $Options);
      $SSLNoVerify = GetValue('SSLNoVerify', $Options);
      $SendCookies = GetValue('Cookies', $Options);
      $CloseSesssion = GetValue('CloseSession', $Options);
      $Redirected = GetValue('Redirected', $Options);
      $Debug = GetValue('Debug', $Options, FALSE);
      $Simulate = GetValue('Simulate', $Options);
      
      $OldVolume = $this->Loud;
      if ($Debug)
         $this->Loud = TRUE;

      $Url = $RelativeURL;
      $PostData = $QueryParams;
      
      /*
       * If files were provided, preprocess the list and exclude files that don't
       * exist. Also, change the method to POST if it is currently GET and there 
       * are valid files to send.
       */
      
      $SendFiles = array();
      foreach ($Files as $File => $FilePath)
         if (file_exists($FilePath))
            $SendFiles[$File] = $FilePath;
      
      $this->FileTransfer = (bool)sizeof($SendFiles);
      if ($this->FileTransfer && $RequestMethod == "GET") {
         $this->Options['Method'] = 'POST';
         $RequestMethod = GetValue('Method', $Options);
      }
      
      /*
       * If extra headers were provided, preprocess the list into the correct 
       * format for inclusion into both cURL and fsockopen header queues.
       */
      
      // Tack on Host header if forced
      if (!is_null($ForceHost))
         $ExtraHeaders['Host'] = $ForceHost;
      
      $SendExtraHeaders = array();
      foreach ($ExtraHeaders as $ExtraHeader => $ExtraHeaderValue)
         $SendExtraHeaders[] = "{$ExtraHeader}: {$ExtraHeaderValue}";
         
      /*
       * If the request is being saved to a file, prepare to save to the 
       * filesystem.
       */
      $this->SaveFile = FALSE;
      if ($SaveAs) {
         $SavePath = dirname($SaveAs);
         $CanSave = @mkdir($SavePath, 0775, TRUE);
         if (!is_writable($SavePath))
            throw new Exception("Cannot write to save path: {$SavePath}");
         
         $this->SaveFile = $SaveAs;
      }
      
      /*
       * Parse Query Parameters and collapse into a querystring in the case of
       * GETs.
       */
      
      $RequestMethod = strtoupper($RequestMethod);
      switch ($RequestMethod) {
         case 'POST':
            break;
         
         case 'GET':
         default:
            $PostData = http_build_query($PostData);
            if (strlen($PostData)) {
               if (stristr($RelativeURL, '?'))
                  $Url .= '&';
               else
                  $Url .= '?';
               $Url .= $PostData;
            }
            break;
      }
      
      $this->Action("Requesting {$Url}");

      $UrlParts = parse_url($Url);
      
      // Extract scheme
      $Scheme = strtolower(GetValue('scheme', $UrlParts, 'http'));
      $this->Action(" scheme: {$Scheme}");
      
      // Extract hostname
      $Host = GetValue('host', $UrlParts, '');
      $this->Action(" host: {$Host}");
      
      // Extract / deduce port
      $Port = GetValue('port', $UrlParts, NULL);
      if (empty($Port)) $Port = ($Scheme == 'https') ? 443 : 80;
      $this->Action(" port: {$Port}");
      
      // Extract Path&Query
      $Path = GetValue('path', $UrlParts, '');
      $Query = GetValue('query', $UrlParts, '');
      $this->UseSSL = ($Scheme == 'https') ? TRUE : FALSE;
      
      /*
       * ProxyRequest can masquerade as the current user, so collect and encode
       * their current cookies as the default case is to send them.
       */
      
      $Cookie = '';
      $EncodeCookies = TRUE;
      foreach($_COOKIE as $Key => $Value) {
         if (strncasecmp($Key, 'XDEBUG', 6) == 0)
            continue;

         if (strlen($Cookie) > 0)
            $Cookie .= '; ';

         $EncodedValue = ($EncodeCookies) ? urlencode($Value) : $Value;
         $Cookie .= "{$Key}={$EncodedValue}";
      }
      
      // This prevents problems for sites that use sessions.
      if ($CloseSesssion)
         @session_write_close();
      
      $Response = '';
      
      // We need cURL
      if (!function_exists('curl_init'))
         throw new Exception('Encountered an error while making a request to the remote server: Your PHP configuration does not allow cURL requests.');
      
      $Handler = curl_init();
      curl_setopt($Handler, CURLOPT_HEADER, FALSE);
      curl_setopt($Handler, CURLINFO_HEADER_OUT, TRUE);
      curl_setopt($Handler, CURLOPT_RETURNTRANSFER, TRUE);
      curl_setopt($Handler, CURLOPT_USERAGENT, GetValue('HTTP_USER_AGENT', $_SERVER, 'Vanilla/2.0'));
      curl_setopt($Handler, CURLOPT_CONNECTTIMEOUT, $ConnectTimeout);
      curl_setopt($Handler, CURLOPT_HEADERFUNCTION, array($this, 'CurlHeader'));

      if ($FollowRedirects) {
         curl_setopt($Handler, CURLOPT_FOLLOWLOCATION, TRUE);
         curl_setopt($Handler, CURLOPT_AUTOREFERER, TRUE);
         curl_setopt($Handler, CURLOPT_MAXREDIRS, 10);
      }

      if ($this->UseSSL) {
         $this->Action(" Using SSL");
         curl_setopt($Handler, CURLOPT_SSL_VERIFYPEER, !$SSLNoVerify);
         curl_setopt($Handler, CURLOPT_SSL_VERIFYHOST, !$SSLNoVerify);
      }

      if ($Timeout > 0)
         curl_setopt($Handler, CURLOPT_TIMEOUT, $Timeout);

      if ($Cookie != '' && $SendCookies) {
         $this->Action(" Sending client cookies");
         curl_setopt($Handler, CURLOPT_COOKIE, $Cookie);
      }

      if ($this->SaveFile) {
         $this->Action(" Saving to file: {$this->SaveFile}");
         $FileHandle = fopen($this->SaveFile, 'w+');
         curl_setopt($Handler, CURLOPT_FILE, $FileHandle);
      }

      if (sizeof($SendExtraHeaders))
         curl_setopt($Handler, CURLOPT_HTTPHEADER, $SendExtraHeaders);

      if ($RequestMethod == 'POST') {
         if ($this->FileTransfer)
            foreach ($SendFiles as $File => $FilePath)
               $PostData[$File] = "@{$FilePath}";
         curl_setopt($Handler, CURLOPT_POST, TRUE);
         curl_setopt($Handler, CURLOPT_POSTFIELDS, $PostData);
      }

      // Set URL
      curl_setopt($Handler, CURLOPT_URL, $Url);
      curl_setopt($Handler, CURLOPT_PORT, $Port);

      $this->CurlReceive($Handler);

      if ($Simulate) return NULL;

      curl_close($Handler);
      
      $this->Loud = $OldVolume;
      return $this->ResponseBody;
   }
   
   protected function Action($Message, $Loud = NULL) {
      if ($this->Loud || $Loud) {
         echo "{$Message}\n";
         flush();
         ob_flush();
      }
      
      $this->ActionLog[] = $Message;
   }
}