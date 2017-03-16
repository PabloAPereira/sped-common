<?php

namespace NFePHP\Common\Soap;

/**
 * Soap base class
 *
 * @category  NFePHP
 * @package   NFePHP\Common\Soap\SoapBase
 * @copyright NFePHP Copyright (c) 2017
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    Roberto L. Machado <linux.rlm at gmail dot com>
 * @link      http://github.com/nfephp-org/sped-nfse for the canonical source repository
 */

use NFePHP\Common\Certificate;
use NFePHP\Common\Soap\SoapInterface;
use NFePHP\Common\Exception\SoapException;
use NFePHP\Common\Exception\RuntimeException;
use NFePHP\Common\Strings\Strings;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;
use Psr\Log\LoggerInterface;

abstract class SoapBase implements SoapInterface
{
    //soap parameters
    protected $connection;
    protected $soapprotocol = self::SSL_DEFAULT;
    protected $soaptimeout = 20;
    protected $proxyIP = '';
    protected $proxyPort = '';
    protected $proxyUser = '';
    protected $proxyPass = '';
    protected $prefixes = [1 => 'soapenv', 2 => 'soap'];
    //certificat parameters
    protected $certificate;
    protected $tempdir = '';
    protected $certsdir = '';
    protected $debugdir = '';
    protected $prifile = '';
    protected $pubfile = '';
    protected $certfile = '';
    //log info
    public $responseHead = '';
    public $responseBody = '';
    public $requestHead = '';
    public $requestBody = '';
    public $soaperror = '';
    public $soapinfo = [];
    public $debugmode = false;
    //flysystem
    protected $adapter;
    protected $filesystem;


    /**
     * Constructor
     * @param Certificate $certificate
     * @param LoggerInterface $logger
     */
    public function __construct(Certificate $certificate = null, LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->certificate = $certificate;
        $this->setTemporaryFolder(sys_get_temp_dir() . '/sped/');
    }
    
    public function __destruct()
    {
        $this->removeTemporarilyFiles($this->certsdir);
    }
    
    /**
     * Set another temporayfolder for saving certificates for SOAP utilization
     * @param string $folderRealPath
     */
    public function setTemporaryFolder($folderRealPath)
    {
        $this->tempdir = $folderRealPath;
        $this->setLocalFolder($folderRealPath);
    }
    
    /**
     * Set Local folder for flysystem
     * @param string $folder
     */
    protected function setLocalFolder($folder = '')
    {
        $this->adapter = new Local($folder);
        $this->filesystem = new Filesystem($this->adapter);
    }

    /**
     * Set debug mode, this mode will save soap envelopes in temporary directory
     * @param bool $value
     */
    public function setDebugMode($value = false)
    {
        $this->debugmode = $value;
    }
    
    /**
     * Set certificate class for SSL comunications
     * @param Certificate $certificate
     */
    public function loadCertificate(Certificate $certificate)
    {
        $this->certificate = $certificate;
    }
    
    /**
     * Set logger class
     * @param LoggerInterface $logger
     */
    public function loadLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * Set timeout for communication
     * @param int $timesecs
     */
    public function timeout($timesecs)
    {
        $this->soaptimeout = $timesecs;
    }
    
    /**
     * Set security protocol
     * @param int $protocol
     */
    public function protocol($protocol = self::SSL_DEFAULT)
    {
        $this->soapprotocol = $protocol;
    }
    
    /**
     * Set prefixes
     * @param string $prefixes
     */
    public function setSoapPrefix($prefixes)
    {
        $this->prefixes = $prefixes;
    }
    
    /**
     * Set proxy parameters
     * @param string $ip
     * @param int $port
     * @param string $user
     * @param string $password
     */
    public function proxy($ip, $port, $user, $password)
    {
        $this->proxyIP = $ip;
        $this->proxyPort = $port;
        $this->proxyUser = $user;
        $this->proxyPass = $password;
    }
    
    /**
     * Send message to webservice
     */
    abstract public function send(
        $url,
        $operation = '',
        $action = '',
        $soapver = SOAP_1_2,
        $parameters = [],
        $namespaces = [],
        $request = '',
        $soapheader = null
    );
    
    /**
     * Mount soap envelope
     * @param string $request
     * @param string $operation
     * @param array $namespaces
     * @param \SOAPHeader $header
     * @return string
     */
    protected function makeEnvelopeSoap(
        $request,
        $operation,
        $namespaces,
        $soapver = SOAP_1_2,
        $header = null
    ) {
        $prefix = $this->prefixes[$soapver];
        $envelope = "<$prefix:Envelope";
        foreach ($namespaces as $key => $value) {
            $envelope .= " $key=\"$value\"";
        }
        $envelope .= ">";
        $soapheader = "<$prefix:Header/>";
        if (!empty($header)) {
            $ns = !empty($header->namespace) ? $header->namespace : '';
            $name = $header->name;
            $soapheader = "<$prefix:Header>";
            $soapheader .= "<$name xmlns=\"$ns\">";
            foreach ($header->data as $key => $value) {
                $soapheader .= "<$key>$value</$key>";
            }
            $soapheader .= "</$name></$prefix:Header>";
        }
        $envelope .= $soapheader;
        $envelope .= "<$prefix:Body>$request</$prefix:Body>"
            . "</$prefix:Envelope>";
        return $envelope;
    }
    
    /**
     * Temporarily saves the certificate keys for use cURL or SoapClient
     */
    public function saveTemporarilyKeyFiles()
    {
        if (!is_object($this->certificate)) {
            throw new RuntimeException(
                'Certificate not found.'
            );
        }
        $this->certsdir = $this->certificate->getCnpj() . '/certs/';
        $this->prifile = $this->certsdir. Strings::randomString(10).'.pem';
        $this->pubfile = $this->certsdir . Strings::randomString(10).'.pem';
        $this->certfile = $this->certsdir . Strings::randomString(10).'.pem';
        $ret = true;
        $ret &= $this->filesystem->put(
            $this->prifile,
            $this->certificate->privateKey
        );
        $ret &= $this->filesystem->put(
            $this->pubfile,
            $this->certificate->publicKey
        );
        $ret &= $this->filesystem->put(
            $this->certfile,
            "{$this->certificate}"
        );
        if (!$ret) {
            throw new RuntimeException(
                'Unable to save temporary key files in folder.'
            );
        }
    }
    
    /**
     * Delete all files in folder
     */
    public function removeTemporarilyFiles($folder)
    {
        $contents = $this->filesystem->listContents($folder, true);
        foreach ($contents as $item) {
            if ($item['type'] == 'file') {
                $this->filesystem->delete($item['path']);
            }
        }
    }
    
    /**
     * Save request envelope and response for debug reasons
     * @param string $operation
     * @param string $request
     * @param string $response
     * @return void
     */
    public function saveDebugFiles($operation, $request, $response)
    {
        if (!$this->debugmode) {
            return;
        }
        $this->debugdir = $this->certificate->getCnpj() . '/debug/';
        $now = \DateTime::createFromFormat('U.u', microtime(true));
        $time = substr($now->format("ymdHisu"), 0, 16);
        try {
            $this->filesystem->put(
                $this->debugdir . $time . "_" . $operation . "_sol.txt",
                $request
            );
            $this->filesystem->put(
                $this->debugdir . $time . "_" . $operation . "_res.txt",
                $response
            );
        } catch (Exception $e) {
            throw new RuntimeException(
                'Unable to create debug files.'
            );
        }
    }
}