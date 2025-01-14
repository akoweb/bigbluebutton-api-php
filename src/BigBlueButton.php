<?php
/**
 * BigBlueButton open source conferencing system - https://www.bigbluebutton.org/.
 *
 * Copyright (c) 2016-2018 BigBlueButton Inc. and by respective authors (see below).
 *
 * This program is free software; you can redistribute it and/or modify it under the
 * terms of the GNU Lesser General Public License as published by the Free Software
 * Foundation; either version 3.0 of the License, or (at your option) any later
 * version.
 *
 * BigBlueButton is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License along
 * with BigBlueButton; if not, see <http://www.gnu.org/licenses/>.
 */
namespace BigBlueButton;

use BigBlueButton\Core\ApiMethod;
use BigBlueButton\Exceptions\ConfigException;
use BigBlueButton\Exceptions\ParsingException;
use BigBlueButton\Exceptions\RuntimeException;
use BigBlueButton\Http\Transport\CurlTransport;
use BigBlueButton\Http\Transport\TransportInterface;
use BigBlueButton\Http\Transport\TransportRequest;
use BigBlueButton\Parameters\CreateMeetingParameters;
use BigBlueButton\Parameters\DeleteRecordingsParameters;
use BigBlueButton\Parameters\EndMeetingParameters;
use BigBlueButton\Parameters\GetMeetingInfoParameters;
use BigBlueButton\Parameters\GetRecordingsParameters;
use BigBlueButton\Parameters\GetRecordingTextTracksParameters;
use BigBlueButton\Parameters\PutRecordingTextTrackParameters;
use BigBlueButton\Parameters\HooksCreateParameters;
use BigBlueButton\Parameters\HooksDestroyParameters;
use BigBlueButton\Parameters\IsMeetingRunningParameters;
use BigBlueButton\Parameters\JoinMeetingParameters;
use BigBlueButton\Parameters\PublishRecordingsParameters;
use BigBlueButton\Parameters\SetConfigXMLParameters;
use BigBlueButton\Parameters\UpdateRecordingsParameters;
use BigBlueButton\Responses\ApiVersionResponse;
use BigBlueButton\Responses\CreateMeetingResponse;
use BigBlueButton\Responses\DeleteRecordingsResponse;
use BigBlueButton\Responses\EndMeetingResponse;
use BigBlueButton\Responses\GetDefaultConfigXMLResponse;
use BigBlueButton\Responses\GetMeetingInfoResponse;
use BigBlueButton\Responses\GetMeetingsResponse;
use BigBlueButton\Responses\GetRecordingsResponse;
use BigBlueButton\Responses\GetRecordingTextTracksResponse;
use BigBlueButton\Responses\PutRecordingTextTrackResponse;
use BigBlueButton\Responses\HooksCreateResponse;
use BigBlueButton\Responses\HooksDestroyResponse;
use BigBlueButton\Responses\HooksListResponse;
use BigBlueButton\Responses\IsMeetingRunningResponse;
use BigBlueButton\Responses\JoinMeetingResponse;
use BigBlueButton\Responses\PublishRecordingsResponse;
use BigBlueButton\Responses\SetConfigXMLResponse;
use BigBlueButton\Responses\UpdateRecordingsResponse;
use BigBlueButton\Util\UrlBuilder;
use SimpleXMLElement;

/**
 * Class BigBlueButton
 * @package BigBlueButton
 */
class BigBlueButton
{
    protected $securitySecret;
    protected $bbbServerBaseUrl;
    protected $urlBuilder;
    protected $jSessionId;
    protected $connectionError;

    const CONNECTION_ERROR_BASEURL = 1;
    const CONNECTION_ERROR_SECRET  = 2;

    /**
     * @var TransportInterface
     */
    private $transport;

    /**
     * @param  string|null             $baseUrl   (optional) If not given, it will be retrieved from the environment.
     * @param  string|null             $secret    (optional) If not given, it will be retrieved from the environment.
     * @param  TransportInterface|null $transport (optional) Use a custom transport for all HTTP requests. Will fallback to default CurlTransport.
     * @throws ConfigException
     */
    public function __construct(?string $baseUrl = null, ?string $secret = null, ?TransportInterface $transport = null)
    {
        // Keeping backward compatibility with older deployed versions
        $this->securitySecret   = $secret ?: getenv('BBB_SECURITY_SALT') ?: getenv('BBB_SECRET');
        $this->bbbServerBaseUrl = $baseUrl ?: getenv('BBB_SERVER_BASE_URL');

        if (empty($this->bbbServerBaseUrl)) {
            throw new ConfigException('Base url required');
        }

        $this->urlBuilder = new UrlBuilder($this->securitySecret, $this->bbbServerBaseUrl);
        $this->transport  = $transport ?? CurlTransport::createWithDefaultOptions();
    }

    /**
     * @return ApiVersionResponse
     *
     * @throws RuntimeException
     */
    public function getApiVersion()
    {
        $xml = $this->processXmlResponse($this->urlBuilder->buildUrl());

        return new ApiVersionResponse($xml);
    }

    /**
     * Check if connection to api can be established with the baseurl and secret
     * @return bool connection successful
     */
    public function isConnectionWorking(): bool
    {
        // Reset connection error
        $this->connectionError = null;

        try {
            $response = $this->isMeetingRunning(
                new IsMeetingRunningParameters('connection_check')
            );

            // url and secret working
            if ($response->success()) {
                return true;
            }

            // Checksum error - invalid secret
            if ($response->hasChecksumError()) {
                $this->connectionError = self::CONNECTION_ERROR_SECRET;

                return false;
            }

            // HTTP exception or XML parse
        } catch (\Exception $e) {
        }

        $this->connectionError = self::CONNECTION_ERROR_BASEURL;

        return false;
    }

    /**
     * Return connection error type
     * @return int|null Connection error (const CONNECTION_ERROR_BASEURL or CONNECTION_ERROR_SECRET)
     */
    public function getConnectionError(): ?int
    {
        return $this->connectionError;
    }

    /* __________________ BBB ADMINISTRATION METHODS _________________ */
    /* The methods in the following section support the following categories of the BBB API:
    -- create
    -- getDefaultConfigXML
    -- setConfigXML
    -- join
    -- end
    */

    /**
     * @param CreateMeetingParameters $createMeetingParams
     *
     * @return string
     */
    public function getCreateMeetingUrl($createMeetingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::CREATE, $createMeetingParams->getHTTPQuery());
    }

    /**
     * @param CreateMeetingParameters $createMeetingParams
     *
     * @return CreateMeetingResponse
     * @throws RuntimeException
     */
    public function createMeeting($createMeetingParams)
    {
        $xml = $this->processXmlResponse($this->getCreateMeetingUrl($createMeetingParams), $createMeetingParams->getPresentationsAsXML());

        return new CreateMeetingResponse($xml);
    }

    /**
     * @return string
     * @deprecated since 4.0 and will be removed in 4.1. The getDefaultConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.
     */
    public function getDefaultConfigXMLUrl()
    {
        @trigger_error(sprintf('"%s()" is deprecated since 4.0 and will be removed in 4.1. The getDefaultConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.', __METHOD__), E_USER_DEPRECATED);

        return $this->urlBuilder->buildUrl(ApiMethod::GET_DEFAULT_CONFIG_XML);
    }

    /**
     * @return GetDefaultConfigXMLResponse
     * @throws RuntimeException
     * @deprecated since 4.0 and will be removed in 4.1. The getDefaultConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.
     */
    public function getDefaultConfigXML()
    {
        @trigger_error(sprintf('"%s()" is deprecated since 4.0 and will be removed in 4.1. The getDefaultConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.', __METHOD__), E_USER_DEPRECATED);

        $xml = $this->processXmlResponse($this->getDefaultConfigXMLUrl());

        return new GetDefaultConfigXMLResponse($xml);
    }

    /**
     * @return string
     * @deprecated since 4.0 and will be removed in 4.1. The setConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.
     */
    public function setConfigXMLUrl()
    {
        @trigger_error(sprintf('"%s()" is deprecated since 4.0 and will be removed in 4.1. The setConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.', __METHOD__), E_USER_DEPRECATED);

        return $this->urlBuilder->buildUrl(ApiMethod::SET_CONFIG_XML, '', false);
    }

    /**
     * @param SetConfigXMLParameters $setConfigXMLParams
     *
     * @return SetConfigXMLResponse
     * @throws RuntimeException
     * @deprecated since 4.0 and will be removed in 4.1. The setConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.
     */
    public function setConfigXML($setConfigXMLParams)
    {
        @trigger_error(sprintf('"%s()" is deprecated since 4.0 and will be removed in 4.1. The setConfigXML API was related to the old Flash client which is no longer available since BigBlueButton 2.2. In BigBlueButton 2.3 the whole API call was removed.', __METHOD__), E_USER_DEPRECATED);

        $setConfigXMLPayload = $this->urlBuilder->buildQs(ApiMethod::SET_CONFIG_XML, $setConfigXMLParams->getHTTPQuery());

        $xml = $this->processXmlResponse($this->setConfigXMLUrl(), $setConfigXMLPayload, 'application/x-www-form-urlencoded');

        return new SetConfigXMLResponse($xml);
    }

    /**
     * @param JoinMeetingParameters $joinMeetingParams
     *
     * @return string
     */
    public function getJoinMeetingURL($joinMeetingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::JOIN, $joinMeetingParams->getHTTPQuery());
    }

    /**
     * @param JoinMeetingParameters $joinMeetingParams
     *
     * @return JoinMeetingResponse
     * @throws RuntimeException
     */
    public function joinMeeting($joinMeetingParams)
    {
        $xml = $this->processXmlResponse($this->getJoinMeetingURL($joinMeetingParams));

        return new JoinMeetingResponse($xml);
    }

    /**
     * @param EndMeetingParameters $endParams
     *
     * @return string
     */
    public function getEndMeetingURL($endParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::END, $endParams->getHTTPQuery());
    }

    /**
     * @param EndMeetingParameters $endParams
     *
     * @return EndMeetingResponse
     * @throws RuntimeException
     * */
    public function endMeeting($endParams)
    {
        $xml = $this->processXmlResponse($this->getEndMeetingURL($endParams));

        return new EndMeetingResponse($xml);
    }

    /**
     * @param IsMeetingRunningParameters $meetingParams
     *
     * @return string
     */
    public function getIsMeetingRunningUrl($meetingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::IS_MEETING_RUNNING, $meetingParams->getHTTPQuery());
    }

    /**
     * @param IsMeetingRunningParameters $meetingParams
     *
     * @return IsMeetingRunningResponse
     * @throws RuntimeException
     */
    public function isMeetingRunning($meetingParams)
    {
        $xml = $this->processXmlResponse($this->getIsMeetingRunningUrl($meetingParams));

        return new IsMeetingRunningResponse($xml);
    }

    /**
     * @return string
     */
    public function getMeetingsUrl()
    {
        return $this->urlBuilder->buildUrl(ApiMethod::GET_MEETINGS);
    }

    /**
     * @return GetMeetingsResponse
     * @throws RuntimeException
     */
    public function getMeetings()
    {
        $xml = $this->processXmlResponse($this->getMeetingsUrl());

        return new GetMeetingsResponse($xml);
    }

    /**
     * @param GetMeetingInfoParameters $meetingParams
     *
     * @return string
     */
    public function getMeetingInfoUrl($meetingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::GET_MEETING_INFO, $meetingParams->getHTTPQuery());
    }

    /**
     * @param GetMeetingInfoParameters $meetingParams
     *
     * @return GetMeetingInfoResponse
     * @throws RuntimeException
     */
    public function getMeetingInfo($meetingParams)
    {
        $xml = $this->processXmlResponse($this->getMeetingInfoUrl($meetingParams));

        return new GetMeetingInfoResponse($xml);
    }

    /**
     * @param GetRecordingsParameters $recordingsParams
     *
     * @return string
     */
    public function getRecordingsUrl($recordingsParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::GET_RECORDINGS, $recordingsParams->getHTTPQuery());
    }

    /**
     * @param GetRecordingsParameters $recordingParams
     *
     * @return GetRecordingsResponse
     * @throws RuntimeException
     */
    public function getRecordings($recordingParams)
    {
        $xml = $this->processXmlResponse($this->getRecordingsUrl($recordingParams));

        return new GetRecordingsResponse($xml);
    }

    /**
     * @param PublishRecordingsParameters $recordingParams
     *
     * @return string
     */
    public function getPublishRecordingsUrl($recordingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::PUBLISH_RECORDINGS, $recordingParams->getHTTPQuery());
    }

    /**
     * @param PublishRecordingsParameters $recordingParams
     *
     * @return PublishRecordingsResponse
     * @throws RuntimeException
     */
    public function publishRecordings($recordingParams)
    {
        $xml = $this->processXmlResponse($this->getPublishRecordingsUrl($recordingParams));

        return new PublishRecordingsResponse($xml);
    }

    /**
     * @param DeleteRecordingsParameters $recordingParams
     *
     * @return string
     */
    public function getDeleteRecordingsUrl($recordingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::DELETE_RECORDINGS, $recordingParams->getHTTPQuery());
    }

    /**
     * @param DeleteRecordingsParameters $recordingParams
     *
     * @return DeleteRecordingsResponse
     * @throws RuntimeException
     */
    public function deleteRecordings($recordingParams)
    {
        $xml = $this->processXmlResponse($this->getDeleteRecordingsUrl($recordingParams));

        return new DeleteRecordingsResponse($xml);
    }

    /**
     * @param UpdateRecordingsParameters $recordingParams
     *
     * @return string
     */
    public function getUpdateRecordingsUrl($recordingParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::UPDATE_RECORDINGS, $recordingParams->getHTTPQuery());
    }

    /**
     * @param UpdateRecordingsParameters $recordingParams
     *
     * @return UpdateRecordingsResponse
     * @throws RuntimeException
     */
    public function updateRecordings($recordingParams)
    {
        $xml = $this->processXmlResponse($this->getUpdateRecordingsUrl($recordingParams));

        return new UpdateRecordingsResponse($xml);
    }

    /**
     * @param GetRecordingTextTracksParameters $getRecordingTextTracksParams
     *
     * @return string
     */
    public function getRecordingTextTracksUrl($getRecordingTextTracksParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::GET_RECORDING_TEXT_TRACKS, $getRecordingTextTracksParams->getHTTPQuery());
    }

    /**
     * @param GetRecordingTextTracksParameters $getRecordingTextTracksParams
     *
     * @return GetRecordingTextTracksResponse
     * @throws RuntimeException
     */
    public function getRecordingTextTracks($getRecordingTextTracksParams)
    {
        return new GetRecordingTextTracksResponse(
            $this->processJsonResponse($this->getRecordingTextTracksUrl($getRecordingTextTracksParams))
        );
    }

    /**
     * @param PutRecordingTextTrackParameters $putRecordingTextTrackParams
     *
     * @return string
     */
    public function getPutRecordingTextTrackUrl($putRecordingTextTrackParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::PUT_RECORDING_TEXT_TRACK, $putRecordingTextTrackParams->getHTTPQuery());
    }

    /**
     * @param PutRecordingTextTrackParameters $putRecordingTextTrackParams
     *
     * @return PutRecordingTextTrackResponse
     * @throws RuntimeException
     */
    public function putRecordingTextTrack($putRecordingTextTrackParams)
    {
        $url  = $this->getPutRecordingTextTrackUrl($putRecordingTextTrackParams);
        $file = $putRecordingTextTrackParams->getFile();

        return new PutRecordingTextTrackResponse(
            $file === null ?
                $this->processJsonResponse($url) :
                $this->processJsonResponse($url, $file, $putRecordingTextTrackParams->getContentType())
        );
    }

    /**
     * @param HooksCreateParameters $hookCreateParams
     *
     * @return string
     */
    public function getHooksCreateUrl($hookCreateParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::HOOKS_CREATE, $hookCreateParams->getHTTPQuery());
    }

    /**
     * @param HooksCreateParameters $hookCreateParams
     *
     * @return HooksCreateResponse
     */
    public function hooksCreate($hookCreateParams)
    {
        $xml = $this->processXmlResponse($this->getHooksCreateUrl($hookCreateParams));

        return new HooksCreateResponse($xml);
    }

    /**
     * @return string
     */
    public function getHooksListUrl()
    {
        return $this->urlBuilder->buildUrl(ApiMethod::HOOKS_LIST);
    }

    /**
     * @return HooksListResponse
     */
    public function hooksList()
    {
        $xml = $this->processXmlResponse($this->getHooksListUrl());

        return new HooksListResponse($xml);
    }

    /**
     * @param HooksDestroyParameters $hooksDestroyParams
     *
     * @return string
     */
    public function getHooksDestroyUrl($hooksDestroyParams)
    {
        return $this->urlBuilder->buildUrl(ApiMethod::HOOKS_DESTROY, $hooksDestroyParams->getHTTPQuery());
    }

    /**
     * @param HooksDestroyParameters $hooksDestroyParams
     *
     * @return HooksDestroyResponse
     */
    public function hooksDestroy($hooksDestroyParams)
    {
        $xml = $this->processXmlResponse($this->getHooksDestroyUrl($hooksDestroyParams));

        return new HooksDestroyResponse($xml);
    }

    /* ____________________ SPECIAL METHODS ___________________ */
    /**
     * @return string
     */
    public function getJSessionId()
    {
        return $this->jSessionId;
    }

    /**
     * @param string $jSessionId
     */
    public function setJSessionId($jSessionId)
    {
        $this->jSessionId = $jSessionId;
    }

    /* ____________________ INTERNAL CLASS METHODS ___________________ */

    /**
     * A private utility method used by other public methods to process XML responses.
     *
     * @param string $url
     * @param string $payload
     * @param string $contentType
     *
     * @return SimpleXMLElement
     * @throws RuntimeException
     */
    private function processXmlResponse($url, $payload = '', $contentType = 'application/xml')
    {
        try {
            return new SimpleXMLElement($this->requestUrl($url, $payload, $contentType));
        } catch (\Exception $e) {
            throw new ParsingException('Could not parse payload as XML', 0, $e);
        }
    }

    /**
     * A private utility method used by other public methods to process json responses.
     *
     * @param string $url
     * @param string $payload
     * @param string $contentType
     *
     * @return string
     * @throws RuntimeException
     */
    private function processJsonResponse($url, $payload = '', $contentType = 'application/json')
    {
        return $this->requestUrl($url, $payload, $contentType);
    }

    /**
     * A private utility method used by other public methods to request from the api.
     *
     * @param string $url
     * @param string $payload
     * @param string $contentType
     *
     * @return string Response body
     *
     * @throws RuntimeException
     */
    private function requestUrl(string $url, string $payload = '', string $contentType = 'application/xml'): string
    {
        $response = $this->transport->request(new TransportRequest($url, $payload, $contentType));

        if (null !== $sessionId = $response->getSessionId()) {
            $this->setJSessionId($sessionId);
        }

        return $response->getBody();
    }
}
