<?php
/*

 Copyright (c) Ampache.org
 All rights reserved.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License v2
 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.

*/

/**
 * vainfo
 * This class takes the information pulled from getID3 and returns it in a
 * Ampache friendly way. 
 */
class vainfo { 

	/* Default Encoding */
	var $encoding = '';
	var $encoding_id3v1 = '';
	var $encoding_id3v2 = '';
	
	/* Loaded Variables */
	var $filename = '';
	var $type = '';
	var $tags = array();

	/* Internal Information */
	var $_raw 		= array();
	var $_raw2		= array();
	var $_getID3 		= '';
	var $_iconv		= false; 
	var $_file_encoding	= '';
	var $_file_pattern	= '';
	var $_dir_pattern	= '';

	/**
	 * Constructor
	 * This function just sets up the class, it doesn't
	 * actually pull the information
	 */
	function vainfo($file,$encoding='',$encoding_id3v1='',$encoding_id3v2='',$dir_pattern,$file_pattern) { 

		$this->filename = $file;
		if ($encoding) { 
			$this->encoding = $encoding;
		}
		else { 
			$this->encoding = Config::get('site_charset');
		}

		/* These are needed for the filename mojo */
		$this->_file_pattern = $file_pattern;
		$this->_dir_pattern  = $dir_pattern;

                // Initialize getID3 engine
                $this->_getID3 = new getID3();

		// get id3tag encodings
		$this->_raw2 = $this->_getID3->analyze($file);
		if(function_exists('mb_detect_encoding')) {
			$this->encoding_id3v1 = array();
			$this->encoding_id3v1[] = mb_detect_encoding($this->_raw2['tags']['id3v1']['artist']['0']);
			$this->encoding_id3v1[] = mb_detect_encoding($this->_raw2['tags']['id3v1']['album']['0']);
			$this->encoding_id3v1[] = mb_detect_encoding($this->_raw2['tags']['id3v1']['genre']['0']);
			$this->encoding_id3v1[] = mb_detect_encoding($this->_raw2['tags']['id3v1']['title']['0']);
			array_multisort($this->encoding_id3v1);
			array_splice($this->encoding_id3v1, -4, 3);
			if($this->encoding_id3v1[0] != "ASCII") {
				$this->encoding_id3v1 = $this->encoding_id3v1[0];
			} else {
				$this->encoding_id3v1 = "ISO-8859-1";
			}
			

			$this->encoding_id3v2 = array();
			$this->encoding_id3v2[] = mb_detect_encoding($this->_raw2['tags']['id3v2']['artist']['0']);
			$this->encoding_id3v2[] = mb_detect_encoding($this->_raw2['tags']['id3v2']['album']['0']);
			$this->encoding_id3v2[] = mb_detect_encoding($this->_raw2['tags']['id3v2']['genre']['0']);
			$this->encoding_id3v2[] = mb_detect_encoding($this->_raw2['tags']['id3v2']['title']['0']);
			array_multisort($this->encoding_id3v2);
			array_splice($this->encoding_id3v2, -4, 3);
			if($this->encoding_id3v2[0] != "ASCII"){
				$this->encoding_id3v2 = $this->encoding_id3v2[0];
			} else {
				$this->encoding_id3v2 = "ISO-8859-1";
			}
		}
		else {
			$this->encoding_id3v1 = $encoding_id3v1;
			$this->encoding_id3v2 = $encoding_id3v2;
		}

                $this->_getID3->option_md5_data		= false;
                $this->_getID3->option_md5_data_source	= false;
		$this->_getID3->option_tags_html	= false;
		$this->_getID3->option_extra_info	= false;
		$this->_getID3->option_tag_lyrics3	= false;
		$this->_getID3->encoding		= $this->encoding; 
		$this->_getID3->encoding_id3v1		= $this->encoding_id3v1;
		$this->_getID3->encoding_id3v2		= $this->encoding_id3v2;
		$this->_getID3->option_tags_process    = true; 

		/* Check for ICONV */
		if (function_exists('iconv')) { 
			$this->_iconv = true;
		}

	} // vainfo


	/**
	 * get_info
	 * This function takes a filename and returns the $_info array
	 * all filled up with tagie goodness or if specified filename
	 * pattern goodness
	 */
	function get_info() {

		/* Get the Raw file information */
		try { 
			$this->_raw = $this->_getID3->analyze($this->filename);
		} 
		catch (Exception $error) { 
			debug_event('getid3',$error->message,'1'); 
		} 

		/* Figure out what type of file we are dealing with */
		$this->type = $this->_get_type();

		/* Get the general information about this file */
		$info = $this->_get_info();


		/* Gets the Tags */
		$this->tags = $this->_get_tags();
		$this->tags['info'] = $info;

		unset($this->_raw);
	
	} // get_info

	/**
	 * _get_type
	 * This function takes the raw information and figures out
	 * what type of file we are dealing with for use by the tag 
	 * function
	 */
	function _get_type() { 

		/* There are a few places that the file type can
		 * come from, in the end we trust the encoding 
		 * type
		 */
		if ($type = $this->_raw['audio']['streams']['0']['dataformat']) { 
			$this->_clean_type($type);
			return $type;
		}
		if ($type = $this->_raw['audio']['dataformat']) { 
			$this->_clean_type($type);
			return $type;
		}
		if ($type = $this->_raw['fileformat']) { 
			$this->_clean_type($type);
			return $type;
		}
		
		return false;

	} // _get_type


	/**
	 * _get_tags
	 * This function takes the raw information and the type and
	 * attempts to gather the tags and then normalize them into
	 * ['tag_name']['var'] = value
	 */
	function _get_tags() { 

		$results = array();

		/* Gather Tag information from the filenames */
		$results['file']	= $this->_parse_filename($this->filename);

		/* Return false if we don't have 
		 * any tags to look at 
		 */
		if (!is_array($this->_raw['tags'])) { 
			return $results; 
		}

		/* The tags can come in many different shapes and colors 
		 * depending on the encoding time of day and phase of the
		 * moon
		 */
		foreach ($this->_raw['tags'] as $key=>$tag_array) { 
			switch ($key) { 
				case 'vorbiscomment':
					$results[$key] = $this->_parse_vorbiscomment($tag_array);
				break;
				case 'id3v1':
					$results[$key] = $this->_parse_id3v1($tag_array);
				break;
				case 'id3v2':
					$results[$key] = $this->_parse_id3v2($tag_array);
				break;
				case 'ape':
					$results[$key] = $this->_parse_ape($tag_array);
				break;
				case 'quicktime':
					$results[$key] = $this->_parse_quicktime($tag_array);
				break;
				case 'riff':
					$results[$key] = $this->_parse_riff($tag_array); 
				break;
				default: 
					debug_event('vainfo','Error: Unable to determine tag type of ' . $key . ' for file ' . $this->filename . ' Assuming id3v2','5');
					$results[$key] = $this->_parse_id3v2($this->_raw['id3v2']['comments']);
				break;
			} // end switch

		} // end foreach

		return $results;

	} // _get_tags

	/**
	 * _get_info
	 * This function gathers and returns the general information
	 * about a song, vbr/cbr sample rate channels etc
	 */
	private function _get_info() { 

		$array = array();

		/* Try to pull the information directly from
		 * the audio array 
		 */
		if ($this->_raw['audio']['bitrate_mode']) { 
			$array['bitrate_mode'] 	= $this->_raw['audio']['bitrate_mode'];
		}
		if ($this->_raw['audio']['bitrate']) { 
			$array['bitrate']	= $this->_raw['audio']['bitrate'];
		}
		if ($this->_raw['audio']['channels']) { 
			$array['channels'] 	= intval($this->_raw['audio']['channels']);
		}
		if ($this->_raw['audio']['sample_rate']) { 
			$array['sample_rate']	= intval($this->_raw['audio']['sample_rate']);
		}
		if ($this->_raw['filesize']) { 
			$array['filesize']	= intval($this->_raw['filesize']);
		}
		if ($this->_raw['encoding']) { 
			$array['encoding']	= $this->_raw['encoding'];
		}
		if ($this->_raw['mime_type']) { 
			$array['mime']		= $this->_raw['mime_type'];
		}
		if ($this->_raw['playtime_seconds']) { 
			$array['playing_time']	= $this->_raw['playtime_seconds'];
		}

		return $array;
	
	} // _get_info

	/**
	 * _clean_type
	 * This standardizes the type that we are given into a reconized 
	 * type
	 */
	private function _clean_type($type) { 

		switch ($type) { 
			case 'mp3':
			case 'mp2':
			case 'mpeg3':
				return 'mp3';
			break;
			case 'flac':
				return 'flac';
			break;
			case 'vorbis':
				return 'ogg';
			break;
			default: 
				/* Log the fact that we couldn't figure it out */
				debug_event('vainfo','Unable to determine file type from ' . $type . ' on file ' . $this->filename,'5');
				return 'unknown';
			break;
		} // end switch on type

	} // _clean_type

	/**
	 * _parse_vorbiscomment
	 * This function takes a vorbiscomment from getid3() and then
	 * returns the elements translated using iconv if needed in a
	 * pretty little format
	 */
	private function _parse_vorbiscomment($tags) { 

		/* Results array */
		$array = array();

		/* go through them all! */
		foreach ($tags as $tag=>$data) { 
			
			/* We need to translate a few of these tags */
			switch ($tag) { 
				case 'tracknumber':
					$array['track']	= $this->_clean_tag($data['0']);
				break;
				case 'discnumber': 
					$array['disk'] 	= $this->_clean_tag($data['0']); 
				break;
				case 'date':
					$array['year']	= $this->_clean_tag($data['0']);
				break;
			} // end switch

			$array[$tag] = $this->_clean_tag($data['0']);

		} // end foreach

		return $array;

	} // _parse_vorbiscomment

	/**
	 * _parse_id3v1
	 * This function takes a id3v1 tag set from getid3() and then
	 * returns the elements translated using iconv if needed in a 
	 * pretty little format
	 */
	private function _parse_id3v1($tags) { 

		$array = array();

		$encoding = $this->_raw['id3v1']['encoding'];
		
		/* Go through all the tags */
		foreach ($tags as $tag=>$data) { 

			/* This is our baseline for naming 
			 * so no translation needed 
			 */
			$array[$tag]	= $this->_clean_tag($data['0'],$encoding);
			
		} // end foreach

		return $array;

	} // _parse_id3v1

	/**
	 * _parse_id3v2
	 * This function takes a id3v2 tag set from getid3() and then
	 * returns the lelements translated using iconv if needed in a
	 * pretty little format
	 */
	private function _parse_id3v2($tags) { 
	
		$array = array();

		/* Go through the tags */
		foreach ($tags as $tag=>$data) { 

			/**
			 * the new getid3 handles this differently 
			 * so we now need to account for it :(
			 */
			switch ($tag) { 
				case 'pos':
					$el = split('/', $data['0']);
					$array['disk'] = $el[0];
				break;
				case 'track_number':
					$array['track'] = $this->_clean_tag($data['0'],'');
				break;	
				break;
				case 'comments':
					$array['comment'] = $this->_clean_tag($data['0'],'');
				break;
				case 'title': 
					$array['title'] = $this->_clean_tag($data['0'],''); 
				break; 
				default: 
					$array[$tag]	= $this->_clean_tag($data['0'],'');
				break;
			} // end switch on tag
		
		} // end foreach

		return $array;

	} // _parse_id3v2

	/**
	 * _parse_ape
	 * This function takes ape tags set by getid3() and then
	 * returns the elements translated using iconv if needed in a
	 * pretty little format
	 */
	private function _parse_ape($tags) { 

		foreach ($tags as $tag=>$data) { 
			
			$array[$tag] = $this->_clean_tag($data['0'],$this->_file_encoding);

		} // end foreach tags 

		return $array;

	} // _parse_ape

	/**
	 * _parse_riff
	 * this function takes the riff take information passed by getid3() and 
	 * then reformats it so that it matches the other formats. May require iconv
	 */
	private function _parse_riff($tags) { 
		
		foreach ($tags as $tag=>$data) { 

			switch ($tag) { 
				case 'product':
					$array['album'] = $this->_clean_tag($data['0'],$this->_file_encoding); 
				break;
				default: 
					$array[$tag] = $this->_clean_tag($data['0'],$this->_file_encoding); 
				break;
			} // end switch on tag

		} // foreach tags

		return $array; 

	} // _parse_riff

	/**
	 * _parse_quicktime
	 * this function takes the quicktime tags set by getid3() and then
	 * returns the elements translated using iconv if needed in a 
	 * pretty little format
	 */
	private function _parse_quicktime($tags) { 

                /* Results array */
                $array = array();

                /* go through them all! */
                foreach ($tags as $tag=>$data) {

                        /* We need to translate a few of these tags */
                        switch ($tag) {
                                case 'creation_date':
					if (strlen($data['0']) > 4) { 
						/* Weird Date format, attempt to normalize */
						$data['0'] = date("Y",strtotime($data['0']));
					}
                                        $array['year']  = $this->_clean_tag($data['0']);
                                break;
                        } // end switch

                        $array[$tag] = $this->_clean_tag($data['0']);

                } // end foreach

                return $array;

	} // _parse_quicktime

	/**
	 * _parse_filename
	 * This function uses the passed file and dir patterns
	 * To pull out extra tag information and populate it into 
	 * it's own array
	 */
	private function _parse_filename($filename) { 

		$results = array();

                // Correctly detect the slash we need to use here
                if (strstr($filename,"/")) {
                        $slash_type = '/';
                }
                else {
                        $slash_type = '\\';
                }

		$pattern = preg_quote($this->_dir_pattern) . $slash_type . preg_quote($this->_file_pattern); 
		preg_match_all("/\%\w/",$pattern,$elements);
		
		$preg_pattern = preg_quote($pattern);
		$preg_pattern = preg_replace("/\%\w/","(.+)",$preg_pattern);
		$preg_pattern = str_replace("/","\/",$preg_pattern);
		$preg_pattern = str_replace(" ","\s",$preg_pattern);
		$preg_pattern = "/" . $preg_pattern . "\..+$/";
		preg_match($preg_pattern,$filename,$matches);
		/* Cut out the Full line, we don't need that */
		array_shift($matches);

		/* Foreach through what we've found */
		foreach ($matches as $key=>$value) { 
			$new_key = translate_pattern_code($elements['0'][$key]);
			if ($new_key) { 
				$results[$new_key] = $value;
			}
		} // end foreach matches 

		return $results;

	} // _parse_filename

	/**
	 * _id3v2_tag_to_frame
	 * This translates the tag name to a frame, if there a many it returns the first
	 * one if finds that exists in the raw
	 */
	private function _id3v2_tag_to_frame($tag_name) { 

		static $map = array(
			'comment'=>array('COM','COMM'),
			'cd_ident'=>array('MCDI','MCI'),
			'album'=>array('TAL','TALB'),
			'language'=>array('TLA','TLAN'),
			'mood'=>array('TMOO'),
			'artist'=>array('TPE1'),
			'year'=>array('TDRC')); 

		foreach ($map[$tag_name] as $frame) { 
			if (isset($this->_raw['id3v2'][$frame])) { 
				return $frame; 
			} 
		} 

		return false; 

	} // _id3v2_tag_to_frame 

	/**
	 * _clean_tag
	 * This function cleans up the tag that it's passed using Iconv
	 * if we've got it. It also takes an optional encoding param
	 * for the cases where we know what the tags source encoding
	 * is, and or if it's different then the encoding recorded
	 * in the file
	 */
	private function _clean_tag($tag,$encoding='') { 

		// If we've got iconv then go ahead and clear her up		
		if ($this->_iconv) { 
			/* Guess that it's UTF-8 */
			if (!$encoding) { $encoding = $this->_getID3->encoding; }
			$charset = $this->encoding . '//TRANSLIT';
			$tag = iconv($encoding,$charset,$tag);
		}

		return $tag;

	} // _clean_tag

} // end class vainfo
?>
