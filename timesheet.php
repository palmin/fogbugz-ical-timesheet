<?php   error_reporting(E_ALL);

//////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// Copyright (c) 2013, Anders Borum
// All rights reserved.
//
// Redistribution and use in source and binary forms, with or without
// modification, are permitted provided that the following conditions are met:
//
// 1. Redistributions of source code must retain the above copyright notice, this
//    list of conditions and the following disclaimer.
// 2. Redistributions in binary form must reproduce the above copyright notice,
//    this list of conditions and the following disclaimer in the documentation
//    and/or other materials provided with the distribution.
//
//    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
//    ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
//    WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
//    DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
//    ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
//    (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
//    LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
//    ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
//    (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
//    SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////

// configuration
$baseURL = "https://fogbugz.klean.dk"; // should not include trailing slash
$defaultDays = 90;

$daysBack = isset($_REQUEST['days']) ? intval($_REQUEST['days']) : $defaultDays;
$server = parse_url($baseURL, PHP_URL_HOST);

// print line ending with CR-LF as required by iCal format and make sure
// newlines are encoded with needed long lines continuation.
function calLine($line) {
    $escaped = str_replace("\n", "\\n", $line);

    // we do not allow last space, as space is used for continuation
    $chunked = substr(chunk_split($escaped, 70, "\r\n "), 0, -1);
    print($chunked);
}

// Format date-string in ISO 8601 UTC format, e.g. 2013-01-21T14:24:06Z into what
// iCal expects, e.g. 20130121T142406Z subtracting the number of seconds.
function formatCalDate($date, $secs = 0)
{
    $time = strtotime($date) - $secs;
    $date = gmdate('c', $time);

    // Luckily conversion between ISO 8601 and iCal dates
    // amounts to fixing time-zone indicator and stripping dashes & colons.
    $date = str_replace('+00:00', 'Z', $date);
    $date = str_replace('-', '', $date);
    $date = str_replace(':', '', $date);
    return $date;
}

function requireAuthenticate($server) {
    header("WWW-Authenticate: Basic realm=\"Use email for $server as username.\"");
    header('HTTP/1.0 401 Unauthorized');
    echo "You need to enter your $server credentials. They are not stored on this server.";
}

// when user supplies password and username we request new token, otherwise we use token to get timesheet
if (!isset($_REQUEST['token'])) {
    if (!isset($_SERVER['PHP_AUTH_USER'])) {
        requireAuthenticate($server);
        exit(0);
    }

    $email = $_SERVER['PHP_AUTH_USER'];
    $passwd = $_SERVER['PHP_AUTH_PW'];
    $url = "$baseURL/api.asp?cmd=logon&email=" . urlencode($email) . "&password=" . urlencode($passwd);
} else {
    $secsPerDay = 3600.0 * 24.0;
    $start = gmdate('c', time() - $secsPerDay * $daysBack);
    $url = sprintf('%s/api.asp?cmd=listIntervals&token=%s&dtstart=%s',
                  $baseURL, urlencode($_REQUEST['token']), $start);
}

$startedCal = false;

// fetch response from FogBugz
$reader = new XMLReader();
$reader->open($url);
while ($reader->read()) {
    switch ($reader->nodeType) {
        case (XMLREADER::ELEMENT):
            $name = $reader->localName;

            // error handling
            if ($name == "error") {
                $code = $reader->getAttribute('code');
                if($code == 1) {
                    requireAuthenticate($server);
                    exit(0);
                }

                if ($code == 3) {
                    // we need token, which is requested when redirect such that there is no token
                    header('HTTP/1.0 301 Moved Permanently');
                    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $path = $_SERVER['PHP_SELF'];
                    header("Location: $scheme://$host$path");
                    exit(0);
                }

                header('HTTP/1.0 403 Forbidden');
                echo $reader->readString() . " [$code]";
                exit(0);
            }

            // login response
            if ($name == 'token') {
                // token from login received, we redirect to URL including token

                // We try to allow webcal scheme with https by specifying port number,
                // and this has not yet been tested;
                $possiblePort = $_SERVER['HTTPS'] ? ":443" : "";
                $scheme = 'webcal';
                // $scheme = $_SERVER['HTTPS'] ? 'https' : 'http';

                $host = $_SERVER['HTTP_HOST'];
                $path = $_SERVER['PHP_SELF'];
                $token = $reader->readString();
                $url = "$scheme://$host$possiblePort$path?token=" . urlencode($token);
                header('HTTP/1.0 301 Moved Permanently');
                //print("Subscribe to this feed:<br/> <a href=\"$url\">$url</a>");
                header("Location: $url");
                exit(0);
            }

            if ($name == 'interval') {
                if (!$startedCal) {
                    $startedCal = true;
                    header('Content-type: text/calendar; charset=utf-8');
                    calLine('BEGIN:VCALENDAR');
                    calLine('VERSION:2.0');
                    calLine('X-WR-CALNAME: FogBugz');
                }

                $xml = $reader->readOuterXML();
                $event = new SimpleXMLElement($xml, LIBXML_NOCDATA);

                $interval = (string)$event->ixInterval;
                $bug = (string)$event->ixBug;
                $start = (string)$event->dtStart;

                // missing end-date means still running, and we choose event.end=now
                $end = (string)$event->dtEnd;
                if($end == '') $end = gmdate('c');

                $title = (string)$event->sTitle;
                $deleted = (string)$event->fDeleted;
                if ($deleted == 'true') continue;

                calLine('BEGIN:VEVENT');
                calLine('UID:' . $interval);
                calLine('DTSTART:' . formatCalDate($start));
                calLine('DTEND:' . formatCalDate($end));
                calLine("SUMMARY:$bug: $title");
                calLine("URL;VALUE=URI:$baseURL/default.asp?$bug");
                calLine('END:VEVENT');
            }

            break;
    }
}

if ($startedCal) {
    calLine('END:VCALENDAR');
}
