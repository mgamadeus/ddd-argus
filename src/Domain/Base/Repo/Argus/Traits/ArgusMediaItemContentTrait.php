<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Traits;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Infrastructure\Exceptions\BadRequestException;
use Exception;

trait ArgusMediaItemContentTrait
{
    use ArgusTrait;

    /**
     * @throws BadRequestException
     */
    protected function getLoadPayload(): ?array
    {
        $this->validateS3Config();

        if (!$this->getParent()->identifier) {
            throw new BadRequestException('No MediaItem name declared!');
        }

        $params =  [
            'query' => [
                'region' => $this->getS3Region(),
                'bucket_name' => $this->getS3Bucket(),
                'object_name' => $this->getFilePath(),
            ]
        ];
        return $params;
    }

    protected function getUpdatePayload(): ?array
    {
        $this->validateS3Config();

        $mediaItemExternalPath = $this->getFilePath();

        $params['body'] = [
            'region' => $this->getS3Region(),
            'bucket_name' => $this->getS3Bucket(),
            'object_name' => $mediaItemExternalPath,
            'compress' => false,
            'data' => $this->base64EncodedContent,
        ];

        return $params;
    }

    /**
     * @throws BadRequestException
     */
    protected function getDeletePayload(): ?array
    {
        return $this->getLoadPayload();
    }

    /**
     * @param mixed $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws BadRequestException
     */
    public function handleUpdateResponse(mixed &$callResponseData, ?ArgusApiOperation &$apiOperation = null): void
    {
        if ($callResponseData->status === 'Bad Request') {
            throw new BadRequestException($callResponseData->message);
        }
        if ($callResponseData->status !== 'OK') {
            throw new Exception($callResponseData->message);
        }
    }

    /**
     * @param mixed $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws BadRequestException
     */
    public function handleDeleteResponse(mixed &$callResponseData, ?ArgusApiOperation &$apiOperation = null): void
    {
        if ($callResponseData->status === 'Bad Request') {
            throw new BadRequestException($callResponseData->message);
        }
        if ($callResponseData->status !== 'OK') {
            throw new Exception($callResponseData->message);
        }
    }

    /**
     * @param mixed|null $callResponseData
     * @param ArgusApiOperation|null $apiOperation
     * @return void
     * @throws BadRequestException
     * @throws Exception
     */
    public function handleLoadResponse(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    ): void {

        if (!(($callResponseData->status ?? null) === 'OK')) {
            $this->handleLoadError($callResponseData, $apiOperation);
            return;
        }

        $this->base64EncodedContent = $callResponseData->data;
        $this->populateMediaItemContentInfo();

        $this->postProcessLoadResponse($callResponseData, true);
    }

    protected function validateS3Config(): void
    {
        if (!$this->getS3Bucket()) {
            throw new BadRequestException('No Bucket declared!');
        }

        if (!$this->getS3Region()) {
            throw new BadRequestException('No Region declared!');
        }
    }

    abstract function getFilePath();

    abstract function handleLoadError(
        mixed &$callResponseData = null,
        ?ArgusApiOperation &$apiOperation = null
    );

    abstract function getS3Bucket();
    abstract function getS3Region();
}
