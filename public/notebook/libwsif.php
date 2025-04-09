<?php
## WSIF support library
## @author legolas558
## @version 1.3.5
## @license GNU/GPL
## (c) 2010 Wiki on a Stick Project
## @url http://stickwiki.sf.net/
##
## offers basic read/write support for WSIF format
#

define('LIBWSIF_VERSION', '1.3.3');
define('_LIBWSIF_RANDOM_CHARSET', "ABCDEFGHIJKLMNOPQRSTUVWXTZabcdefghiklmnopqrstuvwxyz");

define('WSIF_VERSION', '1.3.1');

define('_WSIF_DEFAULT_INDEX', 'index.wsif');

define('_WSIF_NO_ERROR', "No error");
define('_WSIF_NS_VER', "WSIF version %s not supported!");
define('_WSIF_NO_VER', "Could not read WSIF version");
define('_WSIF_NO_HN', "Could not locate header name");
define('_WSIF_BAD_HV', "Could not locate end of header value");
define('_WSIF_IMPORT_FAILURE', "Import failure for page %s");

// some constant for WoaS page attributes
define('_WOAS_ENCRYPTED', 2);
define('_WOAS_EMB_FILE', 4);
define('_WOAS_EMB_IMAGE', 8);

define('_LIBWSIF_UNICODE_REGEX', '[\xC2-\xDF][\x80-\xBF]'.             # non-overlong 2-byte
						// [\x09\x0A\x0D\x20-\x7E]              # ASCII
						'|\xE0[\xA0-\xBF][\x80-\xBF]'.        # excluding overlongs
						'|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'.  # straight 3-byte
						'|\xED[\x80-\x9F][\x80-\xBF]'.        # excluding surrogates
						'|\xF0[\x90-\xBF][\x80-\xBF]{2}'.     # planes 1-3
						'|[\xF1-\xF3][\x80-\xBF]{3}'.          # planes 4-15
						'|\xF4[\x80-\x8F][\x80-\xBF]{2}'     # plane 16
						);

// the default hook used after a page has been loaded from WSIF source
// should return a positive integer (index of created page) if page was
// successfully created, -1 to report failure
function _WSIF_create_page(&$WSIF, $title, &$page, $attrs, $mts = 0) {
	echo sprintf("Page title:\t%s\n%sAttributes:\t%x\nLength:\t\t%d\n---\n",
				$title, $mts ? "Last modified: ".strftime("%Y-%m-%d %H:%M:%S", $mts)."\n" : "",
				$attrs, strlen($page));
	return 0;
}

function _WSIF_stderr_log($msg) {
	fprintf(STDERR, "%s\n", $msg);
}

class WSIF {

	// some private variables
	var $_expected_pages = null;
	var $_emsg = _WSIF_NO_ERROR;
	var $_imported = array();
	var $_log_hook = null;
	var $_loose_merge = false;

	function Load($path, $create_page_hook = '_WSIF_create_page', $log_hook = '_WSIF_stderr_log') {
		$this->_log_hook = $log_hook;
		return $this->_load($path, $create_page_hook, 0);
	}
	
	function Error($msg) {
		$this->_emsg = $msg;
		if (isset($this->_log_hook)) {
			$fn = $this->_log_hook;
			$fn($msg);
		}
	}
	
	function _load($path, $create_page_hook, $recursion = 0) {
		$ct = @file_get_contents($path);
		if ($ct === false)
			return false;
		
		//TODO: initialize some properties when recursion is 0
		
		// the imported pages
		$pfx = "\nwoas.page.";
		$pfx_len = strlen($pfx);
		$fail = false;
		// start looping to find each page
		$p = strpos($ct, $pfx);
		// this is used to mark end-of-block
		$previous_h = null;
		// too early failure
		if ($p === false)
			$this->Error("Invalid WSIF file");
		else { // OK, first page was located, now get some general WSIF info
			if (!preg_match("/^wsif\\.version:\\s+(.*)$/m", substr($ct, 0, $p), $wsif_v)) {
				$this->Error(_WSIF_NO_VER);
				$p = false;
				$fail = true;
			} else {
				// check that WSIF version is not from future or the unsupported 1.0.0
				$wsif_v = $wsif_v[1];
				if (($wsif_v == "1.0.0") || (strnatcmp($wsif_v, WSIF_VERSION)>0)) {
					$this->Error( sprintf(_WSIF_NS_VER, $wsif_v) );
					$p = false;
					$fail = true;
				} else {
					// get number of expected pages (not when recursing)
					if ($recursing == 0) {
						if (preg_match("/^woas\\.pages:\\s+(\\d+)$/m", substr(ct,0,$p), $this->_expected_pages))
							$this->_expected_pages = (int)$this->_expected_pages[1];
					}
				}
			}
		}
		// initialize all the page properties
		$title = $attrs = $last_mod = $len =
				$encoding = $disposition = $d_fn =
				$boundary = $mime = null;
		// position of last header end-of-line
		while ($p !== false) {
			// save last entry offset, used by page definition
			$last_offset = $p;
			// remove prefix
			$sep = strpos($ct, ":", $p+$pfx_len);
			if ($sep === false) {
				$this->Error( _WSIF_NO_HN );
				$fail = true;
				break;
			}
			// get attribute name
			$s = substr($ct,$p+$pfx_len, $sep-$p-$pfx_len);
			// get value
			$p = strpos($ct, "\n", $sep+1);
			if ($p === false) {
				$this->Error( _WSIF_BAD_HV );
				$fail = true;
				break;
			}
			// all headers except the title header can mark an end-of-block
			// the end-of-block is used to find boundaries and content inside
			// them
			if ($s != "title")
				// save the last header position
				$previous_h = $p;
			// get value and apply left-trim
			$v = trim(substr($ct, $sep+1, $p-$sep-1));
			switch ($s) {
				case "title":
					// we have just jumped over a page definition
					if ($title !== null) {
						// store the previously parsed page definition
						$rv = $this->_page_def($create_page_hook, $path,$ct,
								$previous_h,$last_offset,	// offsets to grab the page content
								$title,$attrs,$last_mod,$len,$encoding,$disposition,
								$d_fn,$boundary,$mime, $recursion);
						// save page index for later analysis
						$was_title = title;
						$title = $attrs = $last_mod = $encoding = $len =
							 $boundary = $disposition = $mime = $d_fn = null;
						if (!$rv) // show a message but continue parsing
							$this->Error( sprintf(_WSIF_IMPORT_FAILURE, $was_title) );
						// delete the whole entry to free up memory to GC
						// will delete also the last read header
						$ct = substr($ct, $p);
						$last_offset = $p = 0;
						$previous_h = null;
					}
					// let's start with the next page
					$title = $this->ecma_decode($v);
				break;
				case "attributes":
					$attrs = (int)$v;
				break;
				case "last_modified":
					$last_mod = (int)$v;
				break;
				case "length":
					$len = (int)$v;
				break;
				case "encoding":
					$encoding = $v;
				break;
				case "disposition":
					$disposition = $v;
				break;
				case "disposition.filename":
					$d_fn = $v;
				break;
				case "boundary":
					$boundary = $v;
				break;
				case "mime":
					$mime = $v;
				break;
				case "original_length":
					// ignored for now
				break;
				default:
					$this->_log("Unknown WSIF header: ".$s);
			} // end switch(s)
			if ($fail)
				break;
			// set pointer to next entry
			$p = strpos($ct, $pfx, $p);
		}
		if ($fail)
			return false;
		// process the last page (if any)
		if (($previous_h !== null) && ($title !== null)) {
			$rv = $this->_page_def($create_page_hook, $path,$ct,$previous_h,$last_offset,
					$title,$attrs,$last_mod,$len,$encoding,$disposition,
					$d_fn,$boundary,$mime, $recursion);
			if (!$rv)
				$this->Error( sprintf(_WSIF_IMPORT_FAILURE, $title) );
		}
		// save imported pages
		return count($this->_imported);
	}

	// returns true if a page was defined, and save it in wsif.imported array
	function _page_def($create_page_hook, $path,
						&$ct,			// buffer containing pages
						$p,$last_p,		// start and end offset for section containing the page
						$title,$attrs,$last_mod,$len,$encoding,
						$disposition,$d_fn,$o_boundary,$mime, $recursion = 0) {
		// attributes must be defined
		if (!isset($attrs)) {
			$this->_log("No attributes defined for page \"".$title."\"");
			return false;
		}
		if (!isset($disposition)) {
			$this->_log("No disposition defined for page \"".$title."\"");
			return false;
		}
		// last modified timestamp can be omitted
		if ($last_mod === null)
			$last_mod = 0;
		switch ($disposition) {
			case "inline":
			// craft the exact boundary match string
			$boundary = "\n--".$o_boundary."\n";
			// locate start and ending boundaries
			$bpos_s = strpos($ct, $boundary, $p);
			if ($bpos_s === false) {
				$this->Error( "Failed to find start boundary ".$o_boundary." for page ".$title);
				return false;
			}
			$bpos_e = strpos($ct, $boundary, $bpos_s+strlen($boundary));
			if ($bpos_e === false) {
				$this->Error( "Failed to find end boundary ".$o_boundary." for page ".$title );
				return false;
			}
			// retrieve full page content
			$page = substr($ct, $bpos_s+strlen($boundary), $bpos_e-($bpos_s+strlen($boundary)));
			// length used to check correctness of data segments
			$check_len = strlen($page);
			// split encrypted pages into byte arrays
			if ($attrs & _WOAS_ENCRYPTED) {
				if ($encoding != "8bit/base64") {
					$this->_log("Encrypted page ".$title." is not encoded as 8bit/base64");
					return false;
				}
				//NOTE: in original WoaS, the page would be split into an array of bytes
				$page = base64_decode($page);
			} else if ($attrs & _WOAS_EMB_IMAGE) { // embedded image, not encrypted
				// NOTE: encrypted images are not obviously processed, as per previous 'if'
				if ($encoding != "8bit/base64") {
					$this->_log("Image ".title." is not encoded as 8bit/base64");
					return false;
				}
				if ($mime === null) {
					$this->_log("Image ".$title."has no mime type defined");
					return false;
				}
				// re-add data:uri to images
				$page = "data:".$mime.";base64,".$page;
			} else { // a normal wiki page
				switch ($encoding) {
					case "8bit/base64":
						// base64 files will stay encoded
						if (!($attrs & _WOAS_EMB_FILE))
							// WoaS does not encode pages normally, but this is supported by WSIF format
							$page = base64_decode($page);
					break;
					case "ecma/plain":
						$page = $this->ecma_decode($page);
					break;
					case "8bit/plain": // plain wiki pages are supported
					break;
					default:
						$this->_log("Normal page ".$title." comes with unknown encoding ".$encoding);
						return false;
				}
			}
			// check length (if any were passed)
			if ($len !== null) {
				if ($len != $check_len)
					$this->_log(sprintf("Length mismatch for page %s: ought to be %d but was %d", $title, $len, $check_len));
			}
			// fallback wanted to go to define the page
		break;
		case "external":	// import an external WSIF file
			if ($d_fn === null) {
				$this->Error( "Page ".$title." is external but no filename was specified" );
				return false;
			}
			if ($recursion > 1) {
				$this->Error( "Recursive WSIF import not implemented");
				return false;
			}
			// embedded image/file, not encrypted
			if (($attrs & _WOAS_EMB_FILE) || (attrs & _WOAS_EMB_IMAGE)) {
				if ($encoding != "8bit/plain") {
					$this->Error( "Page ".$title." is an external file/image but not encoded as 8bit/plain");
					return false;
				}
				// load file and apply encode64 (if embedded)
				$page = file_get_contents($the_dir+$d_fn);
				if ($page === false) {
					$this->Error( "Failed load of external "+the_dir+d_fn);
					return false;
				}
				if (($attrs & _WOAS_EMB_FILE) && ($attrs & _WOAS_EMB_IMAGE)) {
					// craft a DATA:URI
					$page = "data:"+mime+";base64,"+base64_encode($page);
				} else if ($attrs & _WOAS_EMB_FILE) {
					$page = base64_encode($page);
				} // otherwise it's binary
				// fallback wanted to apply real page definition later
			} else {
				if ($encoding != "text/wsif") {
					$this->Error( "Page ".$title." is external but not encoded as text/wsif" );
					return false;
				}
				// check the result of external import
				$rv = $this->_load(dirname($path).'/'.$d_fn, $recursion+1);
				if ($rv === false) {
					$this->Error( "Failed import of external ".$d_fn."\n".$this->_emsg);
					// we fail importing this page
					return false;
				}
				// do not run the import hook here because it has been ran by the recursively called function
				return $rv;
			}
		break;
		default:
		 // no disposition or unknown disposition
			$this->Error( "Page \"".$title."\" has invalid disposition: ".$disposition );
			return false;
		} // end of switch
		
		$rv = $create_page_hook($this, $title, $page, $attrs, $last_mod);
		if ($rv != -1) {
			// all OK
			$this->_imported[] = $rv;
			return true;
		}
		// failure from import hook
		return false;
	}

	function _log($msg) {
		fprintf(STDERR, "%s\n", $msg);
	}

	function ecma_decode($s) {
		return str_replace("\\\\", "\\", preg_replace_callback("/\\\\u([0-9a-f]{4})/", array(&$this, '_ecma_decode_cb'),
					$s));
	}
	
	function _ecma_decode_cb($m) {
		$n = $m[1];
		$l = strlen($n);
		$p = 0;
		// skip leading zeroes
		for($i=0;$i<$l;++$i) {
			if ($n[$i] != '0')
				break;
			else $p = $i;
		}
		//extract hexadecimal part
		$n = hexdec(substr($n, $p));
		return $this->_code2utf($n);
	}
	
	function _code2utf($num){
		if($num<128)return chr($num);
		if($num<2048)return chr(($num>>6)+192).chr(($num&63)+128);
		if($num<65536)return chr(($num>>12)+224).chr((($num>>6)&63)+128).chr(($num&63)+128);
		if($num<2097152)return chr(($num>>18)+240).chr((($num>>12)&63)+128).chr((($num>>6)&63)+128) .chr(($num&63)+128);
		trigger_error("UTF8 sequence with value 0x".dechex($num)." is not valid!");
	}
	
	// default behaviour:
	// - wiki pages go inline (utf-8), no container encoding
	// - embedded files/images go outside as blobs
	// - encrypted pages go inline in base64
	function Save($page_read_callback, $path, $single_wsif = true, $inline_wsif = true,
						$author = '', $boundary = '') {
		// the number of blobs which we have already created
		$blob_counter = 0;
		// prepare the extra headers
		$extra = $this->_header('wsif.version', WSIF_VERSION);
		$extra = $this->_header('wsif.generator', 'libwsif');
		$extra .= $this->_header('wsif.generator.version', LIBWSIF_VERSION);
		if (strlen($author))
			$extra .= $this->_header('woas.author', author);

		// boundary used for inline attachments
		$full_wsif = "";
		$done = 0;
		// array of titles
		$titles = array();
		// the attributes prefix, we do not use the page index here for better versioning
		$pfx = "woas.page.";
		while (false !== ($page = $page_read_callback())) {
			$record = $this->_header($pfx."title", $this->ecma_encode($page->title)).
					$this->_header($pfx."attributes", $page->attrs);
			// specify timestamp only if not magic
			if (!$this->_loose_merge && ($page->last_modified != 0))
				$record .= $this->_header($pfx+"last_modified", $page->last_modified);
			$ct = $orig_len = null;
			
			// normalize the page content, set encoding&disposition
			$encoding = null;
			$disposition = "inline";
			if ($page->is_encrypted()) {
				$ct = base64_encode($page->content);
				$encoding = "8bit/base64";
				// special header used for encrypted pages
				$orig_len = strlen($page->content);
			} else {
				$ct = $page->content;
				if ($page->is_embedded()) {
					// if not forced to do them inline, convert for export
					if (!$inline_wsif) {
						$disposition = "external";
						$encoding = "8bit/plain";
						// decode the base64-encoded data
						if ($page->is_image()) {
//							$ct = base64_decode(preg_replace("/data:\\s*[^;]*;\\s*base64,\\s*/A", '', $ct));
							preg_match("/data:\\s*([^;]*);\\s*base64,\\s*/A", $ct, $m);
							$record .= $this->_header($pfx."mime", $m[1]);
							// remove the matched part
							$ct = base64_decode(substr($ct, strlen($m[0]))); unset($m);
						} else // no data:uri for files
							$ct = base64_decode($ct);
					} else {
						$encoding = "8bit/base64";
						if ($page->is_image()) {
							preg_match("/data:\\s*([^;]*);\\s*base64,\\s*/A", $ct, $m);
							$record .= $this->_header($pfx."mime", $m[1]);
							// remove the matched part
							$ct = substr($ct, strlen($m[0])); unset($m);
						}
					}
				} else { // normal wiki pages
					// check if ECMA encoding is necessary
					if ($this->_needs_ecma_encoding($ct)) {
						$ct = $this->ecma_encode($ct);
						$encoding = "ecma/plain";
					} else
						$encoding = "8bit/plain";
				}
			}
			//DEBUG check
			if ($encoding === null) {
				fprintf(STDERR, "Encoding for page "+$page->title+" is set to null!");
				continue;
			}
			// update the index (if needed)
			if (!$single_wsif) {
				$full_wsif .= $this->_header($pfx."title", $this->ecma_encode($page->title));
				// a new mime type
				$full_wsif .= $this->_header($pfx."encoding", "text/wsif");
				$full_wsif .= $this->_header($pfx."disposition", "external");
				// add reference to the external WSIF file
				$full_wsif .= $this->_header($pfx."disposition.filename", sprintf("%d", count($titles)).".wsif")."\n";
			}
			// update record
			if (!$this->_loose_merge)
				$record .= $this->_header($pfx."length", strlen($ct));
			$record .= $this->_header($pfx."encoding", $encoding);
			$record .= $this->_header($pfx."disposition", $disposition);
			// note: when disposition is inline, encoding cannot be 8bit/plain for embedded/encrypted files
			if ($disposition == "inline") {
				// output the original length header (if available)
				if (isset($orig_len))
					$record .= $this->_header($pfx."original_length", $orig_len);
				// create the inline page
				$boundary = $this->_generate_random_boundary($boundary, $ct);
				$record .= $this->_header($pfx."boundary", $boundary);
				// add the inline content
				$record .= $this->_inline($boundary, $ct); unset($ct);
			} else {
				// create the blob filename
				$blob_fn = "blob" . (++$blob_counter).$this->_file_ext($page->title);
				// specify path to external filename
				$record .= $this->_header($pfx."disposition.filename", $blob_fn)+"\n";
				// export the blob
				if (!$this->save_file($path . $blob_fn, $ct))
					$this->Log("Could not save ".$blob_fn);
			}
			// the page record is now ready, proceed to save
			if ($single_wsif) {// append to main page record
				$full_wsif .= $record;
				++$done;
			} else { // save each page separately
				if ($this->save_file($path.sprintf("%d", count($titles)).".wsif",
									// also add the pages counter (single)
									$extra.
									($this->_loose_merge ? "" : $this->_header("woas.pages", 1)).
									"\n".$record))
					++$done;
				else
					$this->Log("Could not save ".$path.sprintf("%d", count($titles)).".wsif");
				$titles[] = $page->title;
			}
			// reset the record
			$record = "";
		} // foreach page
		// add the total pages number
		if (!$this->_loose_merge) {
			if ($single_wsif)
				$extra .= $this->_header('woas.pages', $done);
			else
				$extra .= $this->_header('woas.pages', count($titles));
		}
		// build (artificially) an index of all pages
		if (!$single_wsif) {
			foreach($titles as $pi => $title) {
				$full_wsif .= $this->_header($pfx."title", $this->ecma_encode($title));
				// a new mime type
				$full_wsif .= $this->_header($pfx."encoding", "text/wsif");
				$full_wsif .= $this->_header($pfx."disposition", "external");
				// add reference to the external WSIF file
				$full_wsif .= $this->_header($pfx."disposition.filename", sprintf("%d", $pi).".wsif")."\n";
			}
		}
		// output the index WSIF file now
		if (!$this->save_file($path._WSIF_DEFAULT_INDEX, $extra."\n".$full_wsif)) {
			if ($single_wsif)
				$done = 0;
		} // we do not increment page counter when saving index.wsif
		return $done;
	}
	
	function _header($header_name, $value) {
		return $header_name.": ".$value."\n";
	}

	// returns true if text needs ECMA encoding
	// checks if there are UTF-8 characters
	function _needs_ecma_encoding(&$s) {
		// following regex by rabby - http://mobile-website.mobi/php-utf8-vs-iso-8859-1-59
		if (preg_match('%'._LIBWSIF_UNICODE_REGEX.'%xs', $s))
			return true;
		return false;
	}
	
	// perform ECMAScript encoding only on some UTF-8 sequences
	function ecma_encode($s) {
		// fix the >= 128 ascii chars (to prevent UTF-8 characters corruption)
		return $this->utf8_js(str_replace('\\', '\\\\', $s));
	}
	
	function utf8_js($s) {
		return preg_replace_callback('%('._LIBWSIF_UNICODE_REGEX.')+%xs', array(&$this, '_ecma_encode_cb'), $s);
	}
	
	function _ecma_encode_cb( $str ) {
		$str = $str[0];
        $r = "";
        // following code adapted from spam@or-k.de's example
        $values = array();
        $lookingFor = 1;
       
		$l = strlen($str);
        for ($i = 0; $i < $l; $i++ ) {
            $thisValue = ord( $str[ $i ] );
        if ( $thisValue < ord('A') ) {
            // exclude 0-9
            if ($thisValue >= ord('0') && $thisValue <= ord('9')) {
                 // number
                 $r .= chr($thisValue);
            }
            else {
//                 $r .= '%'.dechex($thisValue);
				trigger_error("Unhandled unicode sequence");
            }
        } else {
              if ( $thisValue < 128)
				$r .= $str[ $i ];
              else {
                    if ( count( $values ) == 0 ) $lookingFor = ( $thisValue < 224 ) ? 2 : 3;               
                    $values[] = $thisValue;               
                    if ( count( $values ) == $lookingFor ) {
                        $number = ( $lookingFor == 3 ) ?
                            ( ( $values[0] % 16 ) * 4096 ) + ( ( $values[1] % 64 ) * 64 ) + ( $values[2] % 64 ):
                            ( ( $values[0] % 32 ) * 64 ) + ( $values[1] % 64 );
					$number = dechex($number);
					$r .= "\\u".substr("0000", strlen($number)).$number;
					$values = array();
					$lookingFor = 1;
              } // if
            } // if
        }
        } // for
        return $r;
    }
	
	function _inline($boundary, &$content) {
		return "\n--".$boundary."\n".$content."\n--".$boundary."\n";
	}

	function _file_ext($fn) {
		if (preg_match("/\\.(\\w+)$/", $fn, $m)) {
			return ".".$m[1];
		}
		return "";
	}

	function _generate_random_boundary($b, &$text) {
		if (!strlen($b))
			$b = $this->_random_string(10);
		while (strpos($text, $b) !== false) {
			$b = $this->_random_string(10);
		}
		return $b;
	}
	
	// returns a random string of given string_length
	function _random_string($len) {
		$l = strlen(_LIBWSIF_RANDOM_CHARSET);
		$s = '';
		for ($i=0;$i<$len;++$i) {
			$s .= substr(_LIBWSIF_RANDOM_CHARSET, mt_rand(0,$l-1), 1);
		}
		return $s;
	}
	
	function save_file($path, $content) {
		return file_put_contents($path, $content);
	}

} // class WSIF

class	WoaS_Page {
	
	var $title;
	var $attributes = 0;
	var $last_modified = 0;
	var $content;
	
	function is_encrypted() {
		return ($this->attributes & _WOAS_ENCRYPTED);
	}
	
	function is_image() {
		return ($this->attributes & _WOAS_EMB_IMAGE);
	}

	function is_embedded() {
		return ($this->attributes & _WOAS_EMB_FILE);
	}

} // class WoaS_Page

?>
