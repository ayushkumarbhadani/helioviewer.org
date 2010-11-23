<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */
/**
 * Movie_HelioviewerMovie Class Definition
 *
 * PHP version 5
 *
 * @category Movie
 * @package  Helioviewer
 * @author   Keith Hughitt <keith.hughitt@nasa.gov>
 * @author   Jaclyn Beck <jaclyn.r.beck@gmail.com>
 * @license  http://www.mozilla.org/MPL/MPL-1.1.html Mozilla Public License 1.1
 * @link     http://launchpad.net/helioviewer.org
 */
require_once 'src/Image/Composite/HelioviewerCompositeImage.php';
require_once 'src/Helper/DateTimeConversions.php';
require_once 'src/Database/ImgIndex.php';
require_once 'src/Movie/FFMPEGEncoder.php';
/**
 * Represents a static (e.g. ogv/mp4) movie generated by Helioviewer
 *
 * Note: For movies, it is easiest to work with Unix timestamps since that is what is returned
 *       from the database. To get from a javascript Date object to a Unix timestamp, simply
 *       use "date.getTime() * 1000." (getTime returns the number of miliseconds)
 *
 * @category Movie
 * @package  Helioviewer
 * @author   Keith Hughitt <keith.hughitt@nasa.gov>
 * @author   Jaclyn Beck <jaclyn.r.beck@gmail.com>
 * @license  http://www.mozilla.org/MPL/MPL-1.1.html Mozilla Public License 1.1
 * @link     http://launchpad.net/helioviewer.org
 */
class Movie_HelioviewerMovie
{
    private $_db;
    private $_layers;
    private $_roi;
    private $_directory;
    private $_filename;
    private $_startTimestamp;
    private $_startDateString;
    private $_endTimestamp;
    private $_endDateString;
    private $_frames;
    private $_frameRate;
    private $_estimatedNumFrames;
    private $_actualNumFrames;
    
    /**
     * Prepares the parameters passed in from the api call and makes a movie from them.
     *
     * @return {String} a url to the movie, or the movie will display.
     */
    public function __construct($layers, $startTimeStr, $roi, $options)
    {
        $defaults = array(
            'endTime'     => false,
            'filename'    => false,
            'frameRate'   => false,
            'numFrames'   => false,
            'outputDir'   => "",
            'quality'     => 10,
            'uuid'        => false,
            'watermarkOn' => true
        );

        $options = array_replace($defaults, $options);
        
        $this->_db        = new Database_ImgIndex();

        $this->_layers    = $layers;
        $this->_roi       = $roi;
        $this->_directory = $this->_createCacheDirectories($options['uuid'], $options['outputDir']);

        $this->_computeMovieStartAndEndDates($startTimeStr, $options['endTime']);
        
        // Compute the estimated number of frames to include in the movie
        $this->_estimatedNumFrames = $this->_getEstimatedNumFrames($options['numFrames']);
        $this->_cadence            = $this->_determineOptimalCadence();  // Movie cadence        
        $this->_timestamps         = $this->_getTimestamps(); // Times to match for each movie frame
        $this->_actualNumFrames    = sizeOf($this->_timestamps);

        // Check to see if a movie can be created for the specified request parameters
        $this->_checkRequestParameters();

        $this->_filename  = $this->_buildFilename($options['filename']);
        $this->_frameRate = $this->_determineOptimalFrameRate($options['frameRate']);

        $this->_setMovieDimensions();

        // Build movie frames
        $images = $this->_buildMovieFrames($options['quality'], $options['watermarkOn']);

        // Compile movie
        $this->_build($images);
    }
    
    /**
     * Validates movie request parameters to ensure that a movie could be made for the request
     */
    private function _checkRequestParameters() {
        try {
            // Make sure number of layers is between one and three
            if ($this->_layers->length() == 0 || $this->_layers->length() > 3) {
                throw new Exception("Invalid layer choices! You must specify 1-3 comma-separated layer names.");
            }
    
            // Make sure that data was found to create a movie
            if ($this->_actualNumFrames == 0) {
                throw new Exception("There are not enough images for the given layers for the given request times.", 1);
            }
        } catch(Exception $e) {
            $this->_abort($e);
        }
    }

    /**
     * Create directories in cache used to store movies
     */
    private function _createCacheDirectories ($uuid, $dir) {
        // Regular movie requests use UUIDs and  event movies use the event identifiers
        if (!$dir) {
            if (!$uuid) {
                $uuid = uuid_create(UUID_TYPE_DEFAULT);
            }
            $dir = HV_CACHE_DIR . "/movies/" . $uuid;
        }

        if (!file_exists($dir)) {
            mkdir("$dir/frames", 0777, true);
            chmod("$dir/frames", 0777);
        }

        return $dir;
    }

    /**
     * Determines filename to use for storing movie
     *
     * @return string filename
     */
    private function _buildFilename($filename) {
        if ($filename) {
            return $filename; 
        }

        $start = str_replace(array(":", "-", "T", "Z"), "_", $this->_startDateString);
        $end   = str_replace(array(":", "-", "T", "Z"), "_", $this->_endDateString);

        return sprintf("%s_%s__%s", $start, $end, $this->_layers->toString());
    }

    /**
     * Determines appropriate start and end times to use for movie generation and returns timestamps for those times
     *
     * NOTE 11/12/2010: This could create conflicts with user's custom settings when attempting movie near now
     *
     * @return void
     */
    private function _computeMovieStartAndEndDates ($startTimeString, $endTimeString)
    {
        $startTime = toUnixTimestamp($startTimeString);

        // Convert to seconds.
        $defaultWindow = HV_DEFAULT_MOVIE_TIME_WINDOW_IN_HOURS * 3600;

        // If endTime is not given, endTime defaults to 24 hours after startTime.
        if (!$endTimeString) {
            $endTime = $startTime + $defaultWindow;
        } else {
            $endTime = toUnixTimestamp($endTimeString);
        }

        $now = time();

        // If startTime is within a day of "now", then endTime becomes "now" and the startTime becomes "now" - 24H.
        if ($now - $startTime < $defaultWindow) {
            $endTime   = $now;
            $startTime = $now - $defaultWindow;
        }
        
        // Store Values as timestamps
        $this->_startTimestamp = $startTime;
        $this->_endTimestamp   = $endTime;

        // And also as date strings
        $this->_startDatestring = toISOString(parseUnixTimestamp($this->_startTimestamp));
        $this->_endDateString   = toISOString(parseUnixTimestamp($this->_endTimestamp));
    }

    /**
     * Takes in meta and layer information and creates movie frames from them.
     *
     * @param {String} $tmpDir     the directory where the frames will be stored
     *
     * @return $images an array of built movie frames
     */
    private function _buildMovieFrames($quality, $watermarkOn)
    {
        $movieFrames  = array();

        $frameNum = 0;

        // Movie frame parameters
        $options = array(
            'quality'    => $quality,
            'watermarkOn'=> $watermarkOn,
            'outputDir'  => $this->_directory . "/frames",
            'interlace'  => false,
            'format'     => 'jpg'
        );

        // Compile frames
        foreach ($this->_timestamps as $time => $closestImages) {
            $obsDate = toISOString(parseUnixTimestamp($time));

            $options = array_merge($options, array(
                'filename'      => "frame" . $frameNum++,
                'closestImages' => $closestImages
            ));

            $screenshot = new Image_Composite_HelioviewerCompositeImage($this->_layers, $obsDate, $this->_roi, $options);
            $filepath   = $screenshot->getFilepath(); 

            array_push($movieFrames, $filepath);
        }

        // Copy the last frame so that it actually shows up in the movie for the same amount of time
        // as the rest of the frames.
        $lastImage = dirname($filepath) . "/frame" . $frameNum . ".jpg";
        
        copy($filepath, $lastImage);
        array_push($movieFrames, $lastImage);

        return $movieFrames;
    }

    /**
     * Fetches the closest images from the database for each given time. Adds them to the timestamp
     * array if they are not duplicates of sets of images in the timestamp array already. $closestImages
     * is an array with one image per layer, associated with their sourceId.
     *
     * @return array
     */
    private function _getTimestamps()
    {
        $timestamps   = array();
        $endTimestamp = $this->_startTimestamp + $this->_estimatedNumFrames * $this->_cadence;

        for ($time = $this->_startTimestamp; $time < $endTimestamp; $time += $this->_cadence) {
            $isoTime = toISOString(parseUnixTimestamp(round($time)));
            $closestImages = $this->_getClosestImagesForTime($isoTime);

            // Only add frames if they are unique
            if ($closestImages != end($timestamps)) {
                $timestamps[round($time)] = $closestImages;

            }
        }

        return $timestamps;
    }

    /**
     * Queries the database to get the closest image to $isoTime for each layer.
     * Returns all images in an associative array with source IDs as the keys.
     *
     * @param {Date}  $isoTime The ISO date string of the timestamp
     *
     * @return array
     */
    private function _getClosestImagesForTime($isoTime)
    {
        $sourceIds  = array();

        foreach ($this->_layers->toArray() as $layer) {
            array_push($sourceIds, $layer['sourceId']);
        }

        $images = array();
        foreach ($sourceIds as $id) {
            $images[$id] = $this->_db->getClosestImage($isoTime, $id);
        }
        return $images;
    }

    /**
     * Uses the startTime and endTime to determine how many frames to make, up to 120.
     *
     * @param Date  $startTime ISO date
     * @param Date  $endTime   ISO date
     *
     * @return the number of frames
     */
    private function _getEstimatedNumFrames($numFrames)
    {
        $maxInRange = 0;

        foreach ($this->_layers->toArray() as $layer) {
            $count      = $this->_db->getImageCount($this->_startDatestring, $this->_endDateString, $layer['sourceId']);
            $maxInRange = max($maxInRange, $count);
        }

        // If the user specifies numFrames, use the minimum of their number and the maximum images in range.
        if ($numFrames !== false) {
            $numFrames = min($maxInRange, $numFrames);
        } else {
            $numFrames = $maxInRange;
        }

        return min($numFrames, HV_MAX_MOVIE_FRAMES / $this->_layers->length());
    }

    /**
     * Uses the startTime, endTime, and numFrames to calculate the amount of time in between
     * each frame.
     *
     * @param Date $startTime Unix Timestamp
     * @param Date $endTime   Unix Timestamp
     * @param Int  $numFrames number of frames in the movie
     *
     * @return the number of seconds in between each frame
     */
    private function _determineOptimalCadence()
    {
        return ($this->_endTimestamp - $this->_startTimestamp) / $this->_estimatedNumFrames;
    }

    /**
     * Uses numFrames to calculate the frame rate that should
     * be used when encoding the movie.
     *
     * @return Int optimized frame rate
     */
    private function _determineOptimalFrameRate($requestedFrameRate)
    {
        // Subtract 1 because we added an extra frame to the end
        $frameRate = ($this->_actualNumFrames - 1 ) / HV_DEFAULT_MOVIE_PLAYBACK_IN_SECONDS;

        // Take the smaller number in case the user specifies a larger frame rate than is practical.
        if ($requestedFrameRate) {
            $frameRate = min($frameRate, $requestedFrameRate);
        }

        return max(1, $frameRate);
    }

    /**
     * Builds the requested movie
     *
     * Makes a temporary directory to store frames in, calculates a timestamp for every frame, gets the closest
     * image to each timestamp for each layer. Then takes all layers belonging to one timestamp and makes a movie frame
     * out of it. When done with all movie frames, phpvideotoolkit is used to compile all the frames into a movie.
     *
     * @param array  $builtImages An array of built movie frames (in the form of HelioviewerCompositeImage objects)
     *
     * @return void
     */
    private function _build($builtImages)
    {
        $this->_frames = $builtImages;

        // Create and FFmpeg encoder instance
        $ffmpeg = new Movie_FFMPEGEncoder($this->_frameRate);

        // TODO 11/18/2010: add 'ipod' option to movie requests in place of the 'hqFormat' param
        $ipod = false;

        if ($ipod) {
            $ffmpeg->createIpodVideo($this->_directory, $this->_filename, "mp4", $this->_width, $this->_height);
        }

        // Create an H.264 video using an MPEG-4 (mp4) container format
        $ffmpeg->createVideo($this->_directory, $this->_filename, "mp4", $this->_width, $this->_height);

        //Create alternative container format options (.mov and .flv)
        $ffmpeg->createAlternativeVideoFormat($this->_directory, $this->_filename, "mp4", "mov");
        $ffmpeg->createAlternativeVideoFormat($this->_directory, $this->_filename, "mp4", "flv");

        $this->_cleanup();
    }

    /**
     * Determines dimensions to use for movie and stores them
     * 
     * @return void
     */
    private function _setMovieDimensions() {
        $this->_width  = round($this->_roi->getPixelWidth());
        $this->_height = round($this->_roi->getPixelHeight());

        // Width and height must be divisible by 2 or ffmpeg will throw an error.
        if ($this->_width % 2 === 1) {
            $this->_width += 1;
        }
        
        if ($this->_height % 2 === 1) {
            $this->_height += 1;
        } 
    }

    /**
     * Cancels movie request
     */
    private function _abort($exception) {
        touch($this->_directory . "/INVALID");
        throw new Exception("Unable to create movie: " . $exception->getMessage(), $exception->getCode());
    }

    /**
     * Unlinks all images except the first frame used to create the video.
     *
     * @return void
     */
    private function _cleanup ()
    {
        $preview = array_shift($this->_frames);
        rename($preview, $this->_directory . "/" . $this->_filename . ".jpg");
        
        // Clean up movie frame images that are no longer needed
        foreach ($this->_frames as $image) {
            if (file_exists($image)) {
                unlink($image);
            }
        }

        rmdir($this->_directory . "/frames");
        touch($this->_directory . "/READY");
    }

    /**
     * Adds black border to movie frames if neccessary to guarantee a 16:9 aspect ratio
     *
     * Checks the ratio of width to height and adjusts each dimension so that the
     * ratio is 16:9. The movie will be padded with a black background in JP2Image.php
     * using the new width and height.
     *
     * @return array Width and Height of padded movie frames
     */
    private function _setAspectRatios()
    {
        $width  = $this->_roi->getPixelWidth();
        $height = $this->_roi->getPixelHeight();

        $ratio = $width / $height;

        // Commented out because padding the width looks funny.
        /*
        // If width needs to be adjusted but height is fine
        if ($ratio < 16/9) {
        $adjust = (16/9) * $height / $width;
        $width *= $adjust;
        }
        */
        // Adjust height if necessary
        if ($ratio > 16/9) {
            $adjust = (9/16) * $width / $height;
            $height *= $adjust;
        }

        $dimensions = array("width" => $width, "height" => $height);
        return $dimensions;
    }
    
    /**
     * Returns the base filepath for movie without any file extension
     */
    public function getFilepath()
    {
        return $this->_directory . "/" . $this->_filename;
    }
    
    /**
     * Returns the Base URL to the most recently created movie (without a file extension)
     */
    public function getURL()
    {
        return str_replace(HV_ROOT_DIR, HV_WEB_ROOT_URL, $this->getFilepath());
    }
    
    /**
     * Returns the movie frame rate
     */
    public function getFrameRate()
    {
        return $this->_frameRate;
    }
    
    /**
     * Returns the number of frames in the movie
     */
    public function getNumFrames()
    {
        return $this->_actualNumFrames;
    }
    
    public function getDuration()
    {
        return $this->_actualNumFrames / $this->_frameRate;
    }
    
    /**
     * Returns HTML for a video player with the requested movie loaded
     */
    public function getMoviePlayerHTML()
    {
        $filepath = str_replace(HV_ROOT_DIR, "../", $this->getFilepath());
        $css      = "width: {$this->_width}px; height: {$this->_height}px;";
        $duration = $this->_actualNumFrames / $this->_frameRate;
        ?>
<!DOCTYPE html> 
<html> 
<head> 
    <title>Helioviewer.org - <?php echo $this->_filename;?></title>            
    <script type="text/javascript" src="http://html5.kaltura.org/js"></script> 
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.js" type="text/javascript"></script>
</head> 
<body>
<div style="text-align: center;">
    <div style="margin-left: auto; margin-right: auto; <?php echo $css;?>";>
        <video style="margin-left: auto; margin-right: auto;" poster="<?php echo "$filepath.jpg"?>" durationHint="<?php echo $duration?>">
            <source src="<?php echo "$filepath.mp4"?>" /> 
            <source src="<?php echo "$filepath.mov"?>" />
            <source src="<?php echo "$filepath.flv"?>" /> 
        </video>
    </div>
</div>
</body> 
</html> 
        <?php        
    }
}
?>
