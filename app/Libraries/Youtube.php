<?php

namespace App\Libraries;

/**
 * YouTube Library
 * 
 * PHP class for embedding YouTube videos.
 * Migrated from CI3 Youtube.php library.
 */
class Youtube
{
    public $video;
    private $width;
    private $height;
    public $error = "<b>Video not found, sorry</b>";

    /**
     * Constructor
     * 
     * @param int $width Video width (default: 640)
     * @param int $height Video height (default: 360)
     */
    public function __construct($width = 640, $height = 360)
    {
        $this->width = $width;
        $this->height = $height;

        if ($this->width < 220) {
            // minimum width
            $this->width = 220;
        }
        if ($this->height < 220) {
            // minimum height
            $this->height = 220;
        }
    }

    /**
     * Play video - generates embed code
     * 
     * @return string HTML iframe code or error message
     */
    public function playVideo()
    {
        // Default video settings
        $youtube_list = false;
        $youtube_video = false;

        // always check contains youtube.com or youtube.be as a reference....
        if (stripos($this->video, "youtube.com") !== false || stripos($this->video, "youtube.be") !== false || stripos($this->video, "youtu.be") !== false) {
            // Test if the video contains a query..
            $test = parse_url($this->video);

            if (isset($test['query'])) {
                $testing = $test['query'];
                parse_str($testing, $params);
                
                if (isset($params['v']) && isset($params['list'])) {
                    // we're dealing with a play list and a selected video.
                    $test = $params['list'];
                    $youtube_list = true;
                }
                if (isset($params['list']) && empty($params['v'])) {
                    // we're only dealing with a play list.
                    $test = $params['list'];
                    $youtube_list = true;
                }
                if (isset($params['v']) && empty($params['list'])) {
                    // we're only dealing with a single video.
                    $test = $params['v'];
                    $youtube_video = true;
                }
                if (empty($params['v']) && empty($params['list'])) {
                    // we're not dealing with a valid request.
                    $youtube_video = false;
                }
            } else {
                // Apparently we're dealing with a shared link.
                $testing = parse_url($this->video, PHP_URL_PATH);
                $test = stristr($testing, "/");
                $test = substr($test, 1);
                $youtube_video = true;
            }

            if ($youtube_video == true) {
                // Display a single video
                $play = '<iframe width="' . $this->width . '" height="' . $this->height . '" src="https://www.youtube.com/embed/' . htmlspecialchars($test) . '?rel=0" frameborder="0" allowfullscreen></iframe>';
            }

            if ($youtube_list == true) {
                // Display a video play list.
                $youtube_video = true;
                $play = '<iframe width="' . $this->width . '" height="' . $this->height . '" src="https://www.youtube.com/embed/videoseries?list=' . htmlspecialchars($test) . '" frameborder="0" allowfullscreen></iframe>';
            }

            if ($youtube_video == false) {
                // We are unable to determine the video.
                $play = $this->error;
            }
        } else {
            // This is not a valid youtube request
            $play = $this->error;
        }

        // Return the results
        return $this->playVideo = $play;
    }
}
