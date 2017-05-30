<?php

namespace RapidAPI\Service;

use RapidAPI\Exception\PackageException;
use RapidAPI\Exception\RequiredFieldException;

class DataValidator
{
    /** @var array */
    private $blockMetadata = [];

    /** @var array */
    private $requiredFieldError = [];

    /** @var array */
    private $parsedFieldError = [];

    /** @var array */
    private $dataFromRequest = [];

    /** @var array */
    private $parsedValidData = [];

    /** @var array */
    private $urlParams = [];

    /** @var array */
    private $bodyParams = [];

    /**
     * @param $dataFromRequest
     * @param $blockMetadata
     */
    public function setData($dataFromRequest, $blockMetadata)
    {
        $this->blockMetadata = $blockMetadata;
        $this->dataFromRequest = $dataFromRequest;
        $this->parseData();
        $this->checkBlockMetadata();
    }

    /**
     * @return array
     */
    public function getValidData(): array
    {
        return $this->parsedValidData;
    }

    /**
     * @return array
     */
    public function getBlockMetadata(): array
    {
        return $this->blockMetadata;
    }

    /**
     * @return array
     */
    public function getUrlParams(): array
    {
        return $this->urlParams;
    }

    public function getBodyParams(): array
    {
        return $this->bodyParams;
    }

    private function parseData()
    {
        foreach ($this->blockMetadata['args'] as $paramData) {
            if ($paramData['required'] == true) {
                $this->parseRequiredDataFromRequest($paramData);
            } else {
                $this->parseSingleDataFromRequest($paramData);
            }
        }
        $this->checkErrors();
    }

    private function checkErrors()
    {
        if (!empty($this->requiredFieldError)) {
            throw new RequiredFieldException(implode(',', $this->requiredFieldError));
        }
        if (!empty($this->parsedFieldError)) {
            throw new PackageException("Parse error in: " . implode(',', $this->parsedFieldError));
        }
    }

    private function parseRequiredDataFromRequest($paramData)
    {
        if ($this->checkNotEmptyParam($paramData)) {
            $this->parseSingleDataFromRequest($paramData);
        } else {
            $this->requiredFieldError[] = $paramData['name'];
        }
    }

    private function checkNotEmptyParam($paramData)
    {
        $name = $paramData['name'];
        $type = mb_strtolower($paramData['type']);
        $value = $this->getValueFromRequestData($name);
        if ($type == 'array' || $type == 'json') {
            if (!empty($value)) {
                return true;
            }
        } else {
            if ($value != "") {
                // true, false, 1, 0, "asd", "true", "false"
                return true;
            }
        }
        return false;
    }

    private function parseSingleDataFromRequest($paramData)
    {
        $name = $paramData['name'];
        $vendorName = $this->getParamVendorName($paramData);
        $type = mb_strtolower($paramData['type']);
        $value = $this->getValueFromRequestData($name);
        // todo fix double checking required params!
        if ($this->checkNotEmptyParam($paramData)) {
            // todo add new metadata param "nullable" => true (default false) to send "" or "0" param
            switch ($type) {
                case 'json':
                    $this->setJSONValue($paramData, $value, $vendorName);
                    break;
                case 'array':
                    $this->setArrayValue($paramData, $value, $vendorName);
                    break;
                case 'boolean':
                    $this->setBooleanValue($paramData, $value, $vendorName);
                    break;
                case 'number':
                    $this->setIntValue($paramData, $value, $vendorName);
                    break;
                case 'file':
                    $this->setFileValue($paramData, $value, $vendorName);
                    break;
                default:
                    $this->setSingleValidData($paramData, $value, $vendorName);
                    break;
            }
        }
    }

    private function setSingleValidData($paramData, $value, $vendorName)
    {
//        if (!empty($paramData['wrapName'])) {
//            $wrapNameList = explode('.', $paramData['wrapName']);
//            $this->addDepthOfNesting($this->parsedValidData, $wrapNameList, $value, $vendorName, $paramData);
//        } else {
//            $this->parsedValidData[$vendorName] = $value;
//        }
        if (!empty($paramData['urlParam'])) {
            $this->setSingleValidVariable($this->urlParams, $value, $vendorName, $paramData);
        } else {
            $this->setSingleValidVariable($this->bodyParams, $value, $vendorName, $paramData);
        }
    }

    private function setSingleValidVariable(&$data, $value, $vendorName, $paramData)
    {
        if (!empty($paramData['wrapName'])) {
            $wrapNameList = explode('.', $paramData['wrapName']);
            $this->addDepthOfNesting($data, $wrapNameList, $value, $vendorName, $paramData);
        } else {
            if (!empty($paramData['complex'])) {
                $data[$vendorName] = $this->createComplexValue($paramData, $value, $vendorName);
            } else {
//                $array[$deepName][$vendorName] = $value;
                $data[$vendorName] = $value;
            }
        }
    }

    private function addDepthOfNesting(array &$array, &$depthNameList, $value, $vendorName, $paramData)
    {
        $result = [];
        while (!empty($depthNameList)) {
            $deepName = array_shift($depthNameList);
            if (!isset($array[$deepName]) && !empty($depthNameList)) {
                $array[$deepName] = [];
            }
            if (empty($depthNameList)) {
                if (!empty($paramData['complex'])) {
                    $array[$deepName][] = $this->createComplexValue($paramData, $value, $vendorName);
                } else {
                    $array[$deepName][$vendorName] = $value;
                }
            }
            $result = $this->addDepthOfNesting($array[$deepName], $depthNameList, $value, $vendorName, $paramData);
        }

        return $result;
    }

    private function createComplexValue($paramData, $value, $vendorName)
    {
        return [
            $paramData['keyName'] => $vendorName,
            $paramData['valueName'] => $value
        ];
    }

    /**
     * Return param Vendor name or change CamelCase to snake_case
     * @param array $paramData
     * @return string
     */
    private function getParamVendorName(array $paramData): string
    {
        if (!empty($paramData['vendorName'])) {
            return $paramData['vendorName'];
        } else {
            if ($this->toSnakeCase($paramData)) {
                return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $paramData['name']));
            } else {
                return $paramData['name'];
            }
        }
    }

    private function toSnakeCase(array $paramData): bool
    {
        $result = false;
        if (isset($paramData['snakeCase'])) {
            $result = $paramData['snakeCase'];
        } else {
            if (isset($this->blockMetadata['snakeCase'])) {
                $result = $this->blockMetadata['snakeCase'];
            }
        }

        return $result;
    }

    private function setJSONValue($paramData, $value, $vendorName)
    {
        if (!is_array($value)) {
            $normalizeJson = $this->normalizeJson($value);
            $data = json_decode($normalizeJson, true);
            if (json_last_error()) {
                $this->parsedFieldError[] = $paramData['name'];
            } else {
                $this->setSingleValidData($paramData, $data, $vendorName);
            }
        } else {
            $this->setSingleValidData($paramData, $value, $vendorName);
        }
    }

    private function setFileValue($paramData, $value, $vendorName)
    {
        if (!empty($paramData['jsonParse'])) {
            $content = file_get_contents($value);
            $this->setJSONValue($paramData, $content, $vendorName);
        } else {
            if (isset($this->blockMetadata['type']) && $this->blockMetadata['type'] == 'multipart') {
                $content = fopen($value, 'r');
            } else {
                $content = file_get_contents($value);
                if (isset($paramData['base64encode']) && filter_var($paramData['base64encode'], FILTER_VALIDATE_BOOLEAN) == true) {
                    $content = base64_encode($content);
                }
            }
            $this->setSingleValidData($paramData, $content, $vendorName);
        }
    }

    private function setArrayValue($paramData, $value, $vendorName)
    {
        if (mb_strtolower($this->blockMetadata['method']) == 'get') {
            $data = is_array($value) ? implode(',', $value) : $value;
            $this->setSingleValidData($paramData, $data, $vendorName);
        } else {
            $data = is_array($value) ? $value : explode(',', $value);
            $this->setSingleValidData($paramData, $data, $vendorName);
        }
    }

    private function setBooleanValue($paramData, $value, $vendorName)
    {
        $data = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        if (!empty($paramData['toInt']) && filter_var($paramData['toInt'], FILTER_VALIDATE_BOOLEAN) == true) {
            $data = (int) $data;
        }
        if (!empty($paramData['toString'])) {
            $data = $data ? "true": "false";
        }
        $this->setSingleValidData($paramData, $data, $vendorName);
    }

    private function setIntValue($paramData, $value, $vendorName)
    {
        $data = (int) $value;
        $this->setSingleValidData($paramData, $data, $vendorName);
    }

    private function checkBlockMetadata()
    {
        if (!isset($this->blockMetadata['url'])) {
            throw new PackageException("Cant find part of vendor's endpoint");
        }
        if (!isset($this->blockMetadata['method'])) {
            throw new PackageException("Cant find method of vendor's endpoint");
        }
    }

    private function normalizeJson($jsonString)
    {
        $data = preg_replace_callback('~"([\[{].*?[}\]])"~s', function ($match) {
            return preg_replace('~\s*"\s*~', "\"", $match[1]);
        }, $jsonString);

        return str_replace('\"', '"', $data);
    }

    private function getValueFromRequestData($paramName)
    {
        if (isset($this->dataFromRequest['args'][$paramName])) {
            return $this->dataFromRequest['args'][$paramName];
        }
        return null;
    }

    private function getMultipartData($data)
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[] = [
                "name" => $key,
                "contents" => $value
            ];
        }

        return $result;
    }

    private function getBinaryData($data)
    {
        return array_pop($data);
    }

    /**
     * Create array with headers and params for Guzzle
     * @param array  $headers
     * @param array  $urlParam
     * @param array  $params
     * @param string $url
     * @return array
     */
    public function createGuzzleData($url, $headers, $urlParam, $params)
    {
        // todo refactor
        $formParamsFlag = false;
        $method = mb_strtoupper($this->blockMetadata['method']);
        $result = [];
        $result['headers'] = $headers;
        $result['method'] = $method;
        $result['url'] = $url;

        foreach ($headers as $headerName => $headerValue) {
            if (mb_strtolower($headerName) == 'content-type' && mb_strtolower($headerValue) == 'application/x-www-form-urlencoded') {
                $formParamsFlag = true;
            }
        }

        if ($method == 'GET') {
            $result['query'] = array_merge($params, $urlParam);
        } else {
            if (!empty($urlParam)) {
                $result['query'] = $urlParam;
            }

            if ($formParamsFlag) {
                $result['form_params'] = $params;
            } else {
                if (isset($this->blockMetadata['type'])) {
                    $type = mb_strtoupper($this->blockMetadata['type']);
                } else {
                    $type = 'JSON';
                }
                switch ($type) {
                    case 'BINARY':
                        $result['body'] = $this->getBinaryData($params);
                        break;
                    case 'JSON':
                        $result['json'] = $params;
                        break;
                    case 'MULTIPART':
                        $result['multipart'] = $this->getMultipartData($params);
                        break;
                    default:
                        $result['json'] = $params;
                        break;
                }
            }
        }

        return $result;
    }
}