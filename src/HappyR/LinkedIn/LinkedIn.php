<?php

namespace HappyR\LinkedIn;

use HappyR\LinkedIn\Exceptions\LinkedInApiException;
use HappyR\LinkedIn\Http\UrlGenerator;

/**
 * Class LinkedIn
 *
 * @author Tobias Nyholm
 *
 */
class LinkedIn
{
    /**
     * The Application ID.
     *
     * @var string
     */
    protected $appId;

    /**
     * The Application App Secret.
     *
     * @var string
     */
    protected $appSecret;

    /**
     * An array with default user stuff.
     *
     * @var array
     */
    protected $user;

    /**
     * A CSRF state variable to assist in the defense against CSRF attacks.
     */
    protected $state;

    /**
     * The OAuth access token received in exchange for a valid authorization
     * code.  null means the access token has yet to be determined.
     *
     * @var string
     */
    protected $accessToken = null;

    /**
     * @var \HappyR\LinkedIn\Storage\DataStorage storage
     *
     */
    protected $storage;

    /**
     * @var string storageClass
     *
     * The class that handles the storage. Override this value with a subclass
     */
    protected $storageClass = 'HappyR\LinkedIn\Storage\SessionStorage';

    /**
     * @var \HappyR\LinkedIn\Http\UrlGenerator urlGenerator
     *
     */
    protected $urlGenerator;

    /**
     * @var \HappyR\LinkedIn\Http\RequestInterface request
     *
     */
    protected $request;

    /**
     * @var string storageClass
     *
     * The class that handles the storage. Override this value with a subclass
     */
    protected $requestClass = 'HappyR\LinkedIn\Http\Request';

    /**
     * Constructor
     *
     * @param string $appId
     * @param string $appSecret
     */
    public function __construct($appId, $appSecret)
    {
        //save app stuff
        $this->appId = $appId;
        $this->appSecret = $appSecret;

        $this->urlGenerator = new UrlGenerator();

        $this->init();
    }

    /**
     * Init the API by creating some classes.
     *
     * This function could be overrided if you want to change any of these classes
     */
    protected function init()
    {
        $class=$this->requestClass;
        $this->request=new $class();

        $class=$this->storageClass;
        $this->storage=new $class();
    }

    /**
     * Is the current user authenticated?
     *
     *
     * @return bool
     */
    public function isAuthenticated()
    {
        return $this->getUserId() != null;
    }

    /**
     * Get the id of the current user
     *
     * @return string|null returns null if no user found
     */
    public function getUserId()
    {
        $user=$this->getUser();

        if (isset($user['id'])) {
            return $user['id'];
        }

        return null;
    }

    /**
     * Get the user array
     *
     * @return array|null
     */
    public function getUser()
    {
        if ($this->user == null) {
            // if we have not already determined this and cached the result.
            $this->user = $this->getUserFromAvailableData();
        }

        return $this->user;
    }


    /**
     * Determines the connected user by first considering an authorization code, and then
     * falling back to any persistent store storing the user.
     *
     * @return array|null get an user array or null
     */
    protected function  getUserFromAvailableData()
    {
        //get saved values
        $user = $this->storage->get('user', null);
        $persistedAccessToken = $this->storage->get('access_token');

        $accessToken = $this->getAccessToken();

        /**
         * This is true if both statements are true:
         * 1: We got an access token
         * 2: The access token has changed or if we don't got a user
         */
        if ($accessToken && !($user && $persistedAccessToken == $accessToken)) {

            $user = $this->getUserFromAccessToken();
            if ($user) {
                $this->storage->set('user', $user);
            } else {
                $this->storage->clearAll();
            }
        }

        return $user;
    }

    /**
     * Retrieves the user array with the understanding that
     * $this->accessToken has already been set and is
     * seemingly legitimate.
     *
     * You should override this function if you want to change the default user array
     *
     * @return array|null
     */
    protected function getUserFromAccessToken()
    {
        try {
            return $this->api('/v1/people/~:(id,firstName,lastName,headline)');
        } catch (LinkedInApiException $e) {
            return null;
        }
    }

    /**
     * Get the authorization code from the query parameters, if it exists,
     * and otherwise return null to signal no authorization code was
     * discoverable.
     *
     * @return string|null The authorization code, or null if the authorization code could not be determined.
     */
    protected function getCode()
    {
        if (isset($_REQUEST['code'])) {
            $state = $this->getState();
            //if state exists in session and in request and if they are equal
            if (null !== $state && isset($_REQUEST['state']) && $state === $_REQUEST['state']) {
                // CSRF state has done its job, so clear it
                $this->state = null;
                $this->storage->clear('state');

                return $_REQUEST['code'];
            } else {
                throw new LinkedInApiException('CSRF state token does not match one provided.');
            }
        }

        return null;
    }

    /**
     * Determines the access token that should be used for API calls.
     *
     *
     * @return string|null The access token of null if the access token is not found
     */
    public function getAccessToken()
    {
        if ($this->accessToken !== null) {
            // we've done this already and cached it.  Just return.
            return $this->accessToken;
        }

        $newAccessToken = $this->fetchNewAccessToken();
        if ($newAccessToken) {
            $this->setAccessToken($newAccessToken);
        }

        //return the new access token or null
        return $this->accessToken;
    }

    /**
     * Determines and returns the user access token using the authorization code. The intent is to
     * return a valid user access token, or null if one is determined to not be available.
     *
     * @return string|null A valid user access token, or null if one could not be determined.
     */
    protected function fetchNewAccessToken()
    {
        $code = $this->getCode();
        if ($code && $code != $this->storage->get('code')) {
            $accessToken = $this->getAccessTokenFromCode($code);
            if ($accessToken) {
                $this->storage->set('code', $code);
                $this->storage->set('access_token', $accessToken);
                return $accessToken;
            }

            // code was bogus, so everything based on it should be invalidated.
            $this->storage->clearAll();
            throw new LinkedInApiException('Could not get access token');
        }

        // as a fallback, just return whatever is in the persistent
        // store, knowing nothing explicit (signed request, authorization
        // code, etc.) was present to shadow it (or we saw a code in $_REQUEST,
        // but it's the same as what's in the persistent store)
        return $this->storage->get('access_token', null);
    }

    /**
     * Retrieves an access token for the given authorization code
     * (previously generated from www.linkedin.com on behalf of
     * a specific user). The authorization code is sent to www.linkedin.com
     * and a legitimate access token is generated provided the access token
     * and the user for which it was generated all match, and the user is
     * either logged in to LinkedIn or has granted an offline access permission.
     *
     * @param string $code An authorization code.
     * @param string $redirectUri Where the user should be redirected after token is generated.
     *                            Default is the current url
     *
     * @return string|null An access token exchanged for the authorization code, or
     *               null if an access token could not be generated.
     */
    protected function getAccessTokenFromCode($code, $redirectUri = null)
    {
        if (empty($code)) {
            return null;
        }

        if ($redirectUri === null) {
            $redirectUri = $this->urlGenerator->getCurrentUrl();
        }

        try {

            $response = $this->request->create(
                $this->urlGenerator->getUrl(
                    'www',
                    'uas/oauth2/accessToken',
                    array(
                        'grant_type' => 'authorization_code',
                        'code' => $code,
                        'redirect_uri' => $redirectUri,
                        'client_id' => $this->getAppId(),
                        'client_secret' => $this->getAppSecret(),
                    )
                ),
                array(),
                'POST'
            );



        } catch (LinkedInApiException $e) {
            // most likely that user very recently revoked authorization.
            // In any event, we don't have an access token, so say so.
            return null;
        }

        if (empty($response)) {
            return null;
        }

        $responseArray = json_decode($response, true);
        if (!isset($responseArray['access_token'])) {
            return null;
        }

        return $responseArray['access_token'];
    }

    /**
     * Make an API call.
     *
     * $linkedIn->api('/v1/people/~:(id,firstName,lastName,headline)');
     *
     * @param string $resource everything after the domain.
     * @param array $params optional. This is the URL params
     *
     * @return array assoc array from json_decode
     */
    public function api($resource, array $params=array())
    {
        if (!isset($params['oauth2_access_token'])) {
            $params['oauth2_access_token'] = $this->getAccessToken();
        }
        if (!isset($params['format'])) {
            $params['format'] = 'json';
        }

        $url=$this->urlGenerator->getUrl('api', $resource, $params);
        $result= $this->request->create($url);

        return json_decode($result, true);
    }


    /**
     * Get a Login URL for use with redirects. By default, full page redirect is
     * assumed. If you are using the generated URL with a window.open() call in
     * JavaScript, you can pass in display=popup as part of the $params.
     *
     * The parameters:
     * - redirect_uri: the url to go to after a successful login
     * - scope: comma (or space) separated list of requested extended permissions
     *
     * @param array $params Provide custom parameters
     *
     * @return string The URL for the login flow
     */
    public function getLoginUrl($params=array())
    {
        $this->establishCSRFTokenState();
        $currentUrl = $this->urlGenerator->getCurrentUrl();

        // if 'scope' is passed as an array, convert to space separated list
        $scopeParams = isset($params['scope']) ? $params['scope'] : null;
        if ($scopeParams) {
            //if scope is an array
            if (is_array($scopeParams)) {
                $params['scope'] = implode(' ', $scopeParams);
            } elseif (is_string($scopeParams)) {
                //if scope is a string with ',' => make it to an array
                $params['scope'] = str_replace(',', ' ', $scopeParams);
            }
        }

        return $this->urlGenerator->getUrl(
            'www',
            'uas/oauth2/authorization',
            array_merge(
                array(
                    'response_type'=>'code',
                    'client_id' => $this->getAppId(),
                    'redirect_uri' => $currentUrl, // possibly overwritten
                    'state' => $this->state,
                ),
                $params
            )
        );
    }

    /**
     * Lays down a CSRF state token for this process.
     *
     */
    protected function establishCSRFTokenState()
    {
        if ($this->state === null) {
            $this->state = md5(uniqid(mt_rand(), true));
            $this->storage->set('state', $this->state);
        }
    }

    /**
     * Get the state, use this to verify the CSRF token
     *
     *
     * @return string|null
     */
    protected function getState()
    {
        if ($this->state === null) {
            $this->state = $this->storage->get('state', null);
        }

        return $this->state;
    }



    /**
     * Get the app id
     *
     * @return string
     */
    public function getAppId()
    {
        return $this->appId;
    }

    /**
     * Get the app secret
     *
     * @return string
     */
    public function getAppSecret()
    {
        return $this->appSecret;
    }

    /**
     * Get the access token
     *
     * @param string $accessToken
     *
     * @return $this
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;

        return $this;
    }
}