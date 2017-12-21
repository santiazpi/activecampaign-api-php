<?php

namespace ActiveCampaign\Api\V1;

use ActiveCampaign\Api\V1\Exceptions\RequestException;
use ActiveCampaign\Api\V1\Exceptions\TimeoutException;
use ActiveCampaign\Api\V1\Exceptions\ClientException;
use ActiveCampaign\Api\V1\Exceptions\ServerException;
use ActiveCampaign\Api\V1\Exceptions\MissingMethodException;

/**
 * Class AC_Connector
 */
class Connector
{

    /**
     * Default curl timeout after connection established (waiting for the response)
     */
    const DEFAULT_TIMEOUT = 30;

    /**
     * Default curl timeout before connection established (waiting for a server connection)
     */
    const DEFAULT_CONNECTTIMEOUT = 10;

    /**
     * @var string
     */
    public $url;

    /**
     * @var
     */
    public $api_key;

    /**
     * @var string
     */
    public $output = "json";

    /**
     * @var int
     */
    private $connect_timeout = self::DEFAULT_CONNECTTIMEOUT;

    /**
     * @var int
     */
    private $timeout = self::DEFAULT_TIMEOUT;

    /**
     * AC_Connector constructor.
     *
     * @param        $url
     * @param        $api_key
     * @param string $api_user
     * @param string $api_pass
     */
    public function __construct($url, $api_key, $api_user = "", $api_pass = "")
    {
        // $api_pass should be md5() already
        $base = "";
        if (!preg_match("/https:\/\/www.activecampaign.com/", $url)) {
            // not a reseller
            $base = "/admin";
        }
        if (preg_match("/\/$/", $url)) {
            // remove trailing slash
            $url = substr($url, 0, strlen($url) - 1);
        }
        if ($api_key) {
            $this->url = "{$url}{$base}/api.php?api_key={$api_key}";
        } elseif ($api_user && $api_pass) {
            $this->url = "{$url}{$base}/api.php?api_user={$api_user}&api_pass={$api_pass}";
        }
        $this->api_key = $api_key;
    }

    /**
     * @param $name      string The name of the method called on the class in ActiveCampaign->api
     * @param $arguments array  The array of arguments passed in on that call
     * @throws MissingMethodException
     */
    public function __call($name, $args)
    {
        // ie, a method like 'list_'
        $appendUnderscore = (substr($name, -1) === "_");

        // we want the name of the method called by the user, but they don't pass in an underscore when
        // calling the api, so let's trim it off for clarity
        // 'list_' -> 'list'
        $originalName = $appendUnderscore ? substr_replace($name, "", -1) : $name;

        // 'contact_list' -> ['contact', 'list']
        // 'list_' -> ['list']
        $newName = explode('_', $name);

        // ['contact', 'list'] -> ['contact', 'List']
        // ['list'] -> ['list']
        for ($i = 0; $i < count($newName); $i++) {
            // skip the first word in the array
            if ($i !== 0) {
                $newName[$i] = ucfirst($newName[$i]);
            }
        }

        // ['contact', 'List'] -> 'contactList'
        // ['list'] -> 'list'
        $newName = implode('', $newName);

        // 'list' -> 'list_'
        if ($appendUnderscore) {
            $newName .= "_";
        }

        // check if the method name we've created, ie, 'contactList', exists on the class
        if (!method_exists($this, $newName)) {
            $className = get_class($this);
            $error = "The method $originalName does not exist on the class $className";
            throw new MissingMethodException($error);
        }

        // call our new method name on the class, taking the arguments array
        // and applying each entry in the array to an argument in the method
        // $this->$newName($args[0], $args[1], $args[2]);
        call_user_func_array(array($this, $newName), $args);
    }

    /**
     * Test the api credentials
     *
     * @return bool|mixed
     * @throws RequestException
     */
    public function credentialsTest()
    {
        $test_url = "{$this->url}&api_action=user_me&api_output={$this->output}";
        $r = true;
        try {
            $this->curl($test_url);
        } catch (\Exception $e) {
            $r = false;
        }
        return $r;
    }

    /**
     * Set curl timeout
     *
     * @param $seconds
     */
    public function setCurlTimeout($seconds)
    {
        $this->timeout = $seconds;
    }

    /**
     * Get curl timeout
     *
     * @return int
     */
    public function getCurlTimeout()
    {
        return $this->timeout;
    }

    /**
     * Set curl connect timeout
     *
     * @param $seconds
     */
    public function setCurlConnectTimeout($seconds)
    {
        $this->connect_timeout = $seconds;
    }

    /**
     * Get curl connect timeout
     *
     * @return int
     */
    public function getCurlConnectTimeout()
    {
        return $this->connect_timeout;
    }

    /**
     * Make the curl request
     *
     * @param        $url
     * @param array $params_data
     * @param string $verb
     * @param string $custom_method
     *
     * @return mixed
     * @throws RequestException
     * @throws ClientException
     * @throws ServerException
     * @throws TimeoutException
     */
    public function curl($url, $params_data = array(), $verb = "", $custom_method = "")
    {
        if ($this->version == 1) {
            // find the method from the URL.
            $method = preg_match("/api_action=[^&]*/i", $url, $matches);
            if ($matches) {
                $method = preg_match("/[^=]*$/i", $matches[0], $matches2);
                $method = $matches2[0];
            } elseif ($custom_method) {
                $method = $custom_method;
            }
        } elseif ($this->version == 2) {
            $method = $custom_method;
            $url .= "?api_key=" . $this->api_key;
        }

        $request = curl_init();

        curl_setopt($request, CURLOPT_HEADER, 0);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($request, CURLOPT_CONNECTTIMEOUT, $this->getCurlConnectTimeout());
        curl_setopt($request, CURLOPT_TIMEOUT, $this->getCurlTimeout());

        if ($params_data && $verb == "GET") {
            if ($this->version == 2) {
                $url .= "&" . $params_data;
                curl_setopt($request, CURLOPT_URL, $url);
            }
        } else {
            curl_setopt($request, CURLOPT_URL, $url);
            if ($params_data && !$verb) {
                // if no verb passed but there IS params data, it's likely POST.
                $verb = "POST";
            } elseif ($params_data && $verb) {
                // $verb is likely "POST" or "PUT".
            } else {
                $verb = "GET";
            }
        }

        if ($verb == "POST" || $verb == "PUT" || $verb == "DELETE") {
            if ($verb == "PUT") {
                curl_setopt($request, CURLOPT_CUSTOMREQUEST, "PUT");
            } elseif ($verb == "DELETE") {
                curl_setopt($request, CURLOPT_CUSTOMREQUEST, "DELETE");
            } else {
                $verb = "POST";
                curl_setopt($request, CURLOPT_POST, 1);
            }
            $data = "";
            if (is_array($params_data)) {
                foreach ($params_data as $key => $value) {
                    if (is_array($value)) {
                        if (is_int($key)) {
                            // array two levels deep
                            foreach ($value as $key_ => $value_) {
                                if (is_array($value_)) {
                                    foreach ($value_ as $k => $v) {
                                        $k = urlencode($k);
                                        $data .= "{$key_}[{$key}][{$k}]=" . urlencode($v) . "&";
                                    }
                                } else {
                                    $data .= "{$key_}[{$key}]=" . urlencode($value_) . "&";
                                }
                            }
                        } elseif (preg_match('/^field\[.*,0\]/', $key)) {
                            // if the $key is that of a field and the $value is that of an array
                            if (is_array($value)) {
                                // then join the values with double pipes
                                $value = implode('||', $value);
                            }
                            $data .= "{$key}=" . urlencode($value) . "&";
                        } else {
                            // IE: [group] => array(2 => 2, 3 => 3)
                            // normally we just want the key to be a string, IE: ["group[2]"] => 2
                            // but we want to allow passing both formats
                            foreach ($value as $k => $v) {
                                if (!is_array($v)) {
                                    $k = urlencode($k);
                                    $data .= "{$key}[{$k}]=" . urlencode($v) . "&";
                                }
                            }
                        }
                    } else {
                        $data .= "{$key}=" . urlencode($value) . "&";
                    }
                }
            } else {
                // not an array - perhaps serialized or JSON string?
                // just pass it as data
                $data = "data={$params_data}";
            }

            $data = rtrim($data, "& ");

            curl_setopt($request, CURLOPT_HTTPHEADER, array("Expect:"));

            curl_setopt($request, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($request, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($request);

        $this->checkForRequestErrors($request, $response);

        $http_code = (string)curl_getinfo($request, CURLINFO_HTTP_CODE);

        curl_close($request);

        $object = json_decode($response);

        if (!is_object($object) || (!isset($object->result_code) && !isset($object->succeeded) && !isset($object->success))) {
            // add methods that only return a string
            $string_responses = array("tags_list", "segment_list", "tracking_event_remove", "contact_list", "form_html", "tracking_site_status", "tracking_event_status", "tracking_whitelist", "tracking_log", "tracking_site_list", "tracking_event_list");
            if (in_array($method, $string_responses)) {
                return $response;
            }

            $this->throwRequestException($response);
        }

        $object->http_code = $http_code;

        if (isset($object->result_code)) {
            $object->success = $object->result_code;

            if (!(int)$object->result_code) {
                $object->error = $object->result_message;
            }
        } elseif (isset($object->succeeded)) {
            // some calls return "succeeded" only
            $object->success = $object->succeeded;

            if (!(int)$object->succeeded) {
                $object->error = $object->message;
            }
        }

        return $object;
    }

    /**
     * Throw the request exception
     *
     * @param $message
     *
     * @throws RequestException
     */
    protected function throwRequestException($message)
    {
        $requestException = new RequestException;
        $requestException->setFailedMessage($message);

        throw $requestException;
    }

    /**
     * Checks the cURL request for errors and throws exceptions appropriately
     *
     * @param $request
     * @param $response string The response from the request
     * @throws RequestException
     * @throws ClientException
     * @throws ServerException
     * @throws TimeoutException
     */
    protected function checkForRequestErrors($request, $response)
    {
        // if curl has an error number
        if (curl_errno($request)) {
            switch (curl_errno($request)) {
                // curl timeout error
                case CURLE_OPERATION_TIMEDOUT:
                    throw new TimeoutException(curl_error($request));
                    break;
                default:
                    $this->throwRequestException(curl_error($request));
                    break;
            }
        }

        $http_code = (string)curl_getinfo($request, CURLINFO_HTTP_CODE);
        if (preg_match("/^4.*/", $http_code)) {
            // 4** status code
            throw new ClientException($response, $http_code);
        } elseif (preg_match("/^5.*/", $http_code)) {
            // 5** status code
            throw new ServerException($response, $http_code);
        }
    }
}
