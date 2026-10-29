<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Testing\UnitTestCase;

/**
 * ACFを読み込まないユニットテストの環境で、AcfMetaStorageの組み立てと、IDとACFの有無の検査を確かめる。ACFへの委譲の振る舞いは、
 * 実物の無料版ACFの統合テスト(AcfMetaStorageContractTest・AcfBridgeIntegrationTest)で確かめる。
 */
final class AcfMetaStorageTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    /**
     * ACFが有効でなければ(get_fieldが無ければ)、全メソッドがAcfNotActiveExceptionになる。#[UseMetaStorage]の解決と
     * 同じく、AcfMetaStorageはKernelのContainerが、そのKernelを渡して組み立てる。
     *
     * @param Closure(AcfMetaStorage): mixed $call
     */
    #[DataProvider('storageCalls')]
    public function testEveryMethodRequiresActiveAcf(string $method, Closure $call): void
    {
        $appDir = $this->createTempDirectory('acf-meta-storage') . '/app';
        self::assertTrue(mkdir($appDir));
        $kernel = Kernel::boot(KernelConfig::theme($appDir, 'Example\Tests\AcfMetaStorage' . uniqid(), true));
        $storage = $kernel->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);
        self::assertFalse(\function_exists('get_field'));

        $this->expectException(AcfNotActiveException::class);
        $this->expectExceptionMessage(AcfMetaStorage::class . "::{$method} requires Advanced Custom Fields (ACF) 6.2+");

        $call($storage);
    }

    /**
     * ACFが有効でないときの例外のメッセージは、docs/09 §10の正準形の全文(`{callable}`は`AcfMetaStorage::{メソッド}`)と
     * 一致する(受け入れ基準「ACF不在環境で…AcfMetaStorageへのアクセスがAcfNotActiveException(正準メッセージ)になる」)。
     *
     * @param Closure(AcfMetaStorage): mixed $call
     */
    #[DataProvider('storageCalls')]
    public function testAcfNotActiveMessageIsTheCanonicalFullText(string $method, Closure $call): void
    {
        $appDir = $this->createTempDirectory('acf-meta-storage-message') . '/app';
        self::assertTrue(mkdir($appDir));
        $storage = Kernel::boot(KernelConfig::theme($appDir, 'Example\Tests\AcfMetaStorageMessage' . uniqid(), true))->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);

        try {
            $call($storage);
            self::fail('AcfNotActiveException was not thrown');
        } catch (AcfNotActiveException $exception) {
            self::assertSame(
                AcfMetaStorage::class . "::{$method} requires Advanced Custom Fields (ACF) 6.2+ to be installed and active. Install/activate the ACF plugin, or remove tmswp/acf-bridge usages.",
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string, Closure(AcfMetaStorage): mixed}> */
    public static function storageCalls(): iterable
    {
        yield 'has' => ['has', static fn(AcfMetaStorage $storage): bool => $storage->has(ObjectType::Post, 10, 'price')];
        yield 'get' => ['get', static fn(AcfMetaStorage $storage): mixed => $storage->get(ObjectType::Post, 10, 'price')];
        yield 'getAll' => ['getAll', static fn(AcfMetaStorage $storage): array => $storage->getAll(ObjectType::Post, 10, 'price')];
        yield 'getMultiple' => ['getMultiple', static fn(AcfMetaStorage $storage): array => $storage->getMultiple(ObjectType::Post, 10, ['price'])];
        yield 'set' => ['set', static fn(AcfMetaStorage $storage): bool => $storage->set(ObjectType::Post, 10, 'price', '1200')];
        yield 'setAll' => ['setAll', static fn(AcfMetaStorage $storage): bool => $storage->setAll(ObjectType::Post, 10, 'price', ['1200'])];
        yield 'delete' => ['delete', static fn(AcfMetaStorage $storage): bool => $storage->delete(ObjectType::Post, 10, 'price')];
    }

    /**
     * 全メソッドは、1未満のIDをACFの有無の検査より前にInvalidArgumentExceptionにする(ACFはpost_idの0を現在のループの
     * 投稿に置き換え、WordPressのメタの関数は負のIDをabsint()で別のIDにするため)。
     *
     * @param Closure(AcfMetaStorage, ObjectType, int): mixed $call
     */
    #[DataProvider('idCalls')]
    public function testEveryMethodRejectsIdsBelowOne(Closure $call): void
    {
        $appDir = $this->createTempDirectory('acf-meta-storage-id') . '/app';
        self::assertTrue(mkdir($appDir));
        $storage = Kernel::boot(KernelConfig::theme($appDir, 'Example\Tests\AcfMetaStorageId' . uniqid(), true))->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);

        foreach (ObjectType::cases() as $type) {
            foreach ([0, -5] as $id) {
                try {
                    $call($storage, $type, $id);
                    self::fail("InvalidArgumentException was not thrown for {$type->value} {$id}");
                } catch (InvalidArgumentException $exception) {
                    self::assertSame('meta object ID must be a positive integer', $exception->getMessage());
                }
            }
        }
    }

    /** @return iterable<string, array{Closure(AcfMetaStorage, ObjectType, int): mixed}> */
    public static function idCalls(): iterable
    {
        yield 'has' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): bool => $storage->has($type, $id, 'price')];
        yield 'get' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): mixed => $storage->get($type, $id, 'price')];
        yield 'getAll' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): array => $storage->getAll($type, $id, 'price')];
        yield 'getMultiple' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): array => $storage->getMultiple($type, $id, ['price'])];
        yield 'set' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): bool => $storage->set($type, $id, 'price', '1200')];
        yield 'setAll' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): bool => $storage->setAll($type, $id, 'price', ['1200'])];
        yield 'delete' => [static fn(AcfMetaStorage $storage, ObjectType $type, int $id): bool => $storage->delete($type, $id, 'price')];
    }
}
