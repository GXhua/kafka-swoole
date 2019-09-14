<?php
declare(strict_types=1);

namespace Kafka\Protocol;

use Kafka\Enum\ProtocolTypeEnum;
use Kafka\Exception\ProtocolTypeException;
use Kafka\Protocol\Request\Common\RequestHeader;
use Kafka\Protocol\Type\Arrays32;
use Kafka\Protocol\Type\Int32;
use Kafka\Protocol\Type\String16;
use ReflectionProperty;
use ReflectionClass;
use function call_user_func;
use Kafka\Support\Str;

abstract class AbstractRequest extends AbstractRequestOrResponse
{
    /**
     * @var RequestHeader $requestHeader
     */
    protected $requestHeader;

    /**
     * @return RequestHeader
     */
    public function getRequestHeader(): RequestHeader
    {
        return $this->requestHeader;
    }

    /**
     * @param RequestHeader $requestHeader
     *
     * @return AbstractRequest
     */
    public function setRequestHeader(RequestHeader $requestHeader): AbstractRequest
    {
        $this->requestHeader = $requestHeader;

        return $this;
    }

    /**
     * @param null   $fullClassName
     * @param null   $instance
     * @param string $protocol
     *
     * @return string
     * @throws ProtocolTypeException
     * @throws \ReflectionException
     */
    public function pack($fullClassName = null, $instance = null, $protocol = '')
    {
        $fullClassName = $fullClassName ?? static::class;
        $instance = $instance ?? $this;
        $refClass = new ReflectionClass($fullClassName);

        $classNamespace = $refClass->getNamespaceName();
        $typeNamespace = __NAMESPACE__ . '\Type\\';

        $shortClassName = Str::after($fullClassName, "{$classNamespace}\\");

        $refProperties = $this->getProperties($refClass);
        foreach ($refProperties as $refProperty) {
            $propertyComment = $refProperty->getDocComment();
            $propertyName = $refProperty->getName();
            if (preg_match('/.*@var\s+(?P<protocolType>\w+(\[\])?)\s+.*/', $propertyComment, $matches)) {
                $isArray = false;
                if (preg_match('/^(?P<protocolType>.*)\[\]$/', $matches['protocolType'], $matches2)) {
                    $className = $this->correctionClassName($shortClassName, $classNamespace, $typeNamespace,
                        $matches2['protocolType']);
                    $isArray = true;
                } else {
                    $className = $this->correctionClassName($shortClassName, $classNamespace, $typeNamespace,
                        $matches['protocolType']);
                }

                if ($isArray) {
                    $protocolObjectArray = $this->getPropertyValue($instance, $propertyName);
                    $arrayCount = count($protocolObjectArray);
                    $protocol .= pack(Arrays32::getWrapperProtocol(), (string)$arrayCount);
                    foreach ($protocolObjectArray as $protocolObject) {
                        $protocol = $this->pack($className, $protocolObject, $protocol);
                    }
                } else {
                    if ($className === RequestHeader::class) {
                        $protocol = $this->pack($className, $this->getPropertyValue($instance, $propertyName),
                            $protocol);
                    } else {
                        $wrapperProtocol = call_user_func([$className, 'getWrapperProtocol']);
                        $value = (string)$this->getTypePropertyValue($instance, $propertyName, $protocol);
                        echo "[-] {$className}\twrapperProtocol : {$wrapperProtocol}, name: {$propertyName}, value : " . var_export($this->getTypePropertyValue($instance,
                                $propertyName, $protocol),
                                true) . PHP_EOL;
                        if ($className === String16::class) {
                            $protocol .= pack($wrapperProtocol, (string)strlen($value)) . $value;
                        } else {
                            if ($instance instanceof AbstractRequest && $propertyName == 'size') {
                                $protocol = pack($wrapperProtocol, (string)strlen($protocol)) . $protocol;
                            } else {
                                if ($wrapperProtocol == 'N2') {
                                    $left = 0xffffffff00000000;
                                    $right = 0x00000000ffffffff;

                                    $l = ($value & $left) >> 32;
                                    $r = $value & $right;

                                    $protocol .= pack($wrapperProtocol, $l, $r);
                                } else {
                                    $protocol .= pack($wrapperProtocol, $value);
                                }
                            }
                        }
                    }
                }
            } else {
                throw new ProtocolTypeException("protocolType undefined , comment: " . $propertyComment);
            }
        }

        return $protocol;
    }

    /**
     * @param string $shortClassName
     * @param string $classNamespace
     * @param string $typeNamespace
     * @param string $protocolType
     *
     * @return string
     * @throws ProtocolTypeException
     */
    private function correctionClassName(
        string $shortClassName,
        string $classNamespace,
        string $typeNamespace,
        string $protocolType
    ): string
    {
        $className = "{$typeNamespace}{$protocolType}";
        if (!class_exists($className)) {
            if (Str::endsWith($shortClassName, 'Request')) {
                if ($protocolType === 'RequestHeader') {
                    $className = Str::before($classNamespace, 'Request') . "Request\\Common\\{$protocolType}";
                } else {
                    $secondNamespace = Str::before($shortClassName, 'Request');
                    $className = "{$classNamespace}\\{$secondNamespace}\\{$protocolType}";
                }
            } else {
                $className = "{$classNamespace}\\{$protocolType}";
            }
        }

        if (!class_exists($className)) {
            throw new ProtocolTypeException('There are no protocol mines');
        }

        return $className;
    }

    /**
     * @param ReflectionClass $refClass
     *
     * @return array
     */
    private function getProperties(ReflectionClass $refClass): array
    {
        $commonRefProperties = $refClass->getProperties(ReflectionProperty::IS_PROTECTED);
        $refProperties = $refClass->getProperties(ReflectionProperty::IS_PRIVATE);
        if (!empty($commonRefProperties)) {
            [$requestHeader, $size] = $commonRefProperties;
            array_unshift($refProperties, $requestHeader);
            array_push($refProperties, $size);
        }

        return $refProperties;
    }

    /**
     * @param             $instance
     * @param string      $propertyName
     * @param null|string $protocol
     *
     * @return mixed
     */
    private function getTypePropertyValue($instance, string $propertyName, ?string $protocol = '')
    {
        if ($instance instanceof AbstractRequest && $propertyName == 'size') {
            $setMethod = Str::camel('set_' . $propertyName);
            $instance->{$setMethod}(Int32::value(strlen($protocol)));
        }

        $getMethod = Str::camel('get_' . $propertyName);
        $getValueMethod = 'getValue';
        $value = $instance->{$getMethod}()->{$getValueMethod}();

        return $value;
    }

    /**
     * @param             $instance
     * @param string      $propertyName
     *
     * @return mixed
     */
    private function getPropertyValue($instance, string $propertyName)
    {
        $getMethod = Str::camel('get_' . $propertyName);
        $value = $instance->{$getMethod}();

        return $value;
    }
}