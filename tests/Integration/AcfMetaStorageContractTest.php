<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\Core\Meta\MetaStorageInterface;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Testing\MetaStorageContractTestCase;

/**
 * AcfMetaStorageがMetaStorageInterfaceの契約(docs/04 §4.5)を満たすことを、有効化した無料版ACFで確かめる。
 *
 * 契約のキーは、fixtureのテーマのacf-json(group_tmswp_acf_contract.json)のtextフィールド
 * (tmswp_acf_contract_{名前})にし、ACFのフィールドのキーの経路(値と参照のメタを書く)で確かめる。setAll()で複数の行を
 * 書くキーだけは、ACFのフィールドでないキーにする(key())。
 * ACFのフィールドでないキーの経路は、AcfBridgeIntegrationTestで確かめる。
 */
final class AcfMetaStorageContractTest extends MetaStorageContractTestCase
{
    /** 契約のキーがACFのフィールドで、set()が参照のメタにフィールドのkeyを書き、ACFがその値をフィールドとして読む。 */
    public function testContractKeysAreAcfFieldsWrittenWithTheReference(): void
    {
        $id = $this->createObject();
        $key = $this->key('single');
        $field = acf_get_field($key);

        self::assertIsArray($field);
        self::assertSame('field_tmswp_acf_contract_single', $field['key']);
        self::assertTrue($this->storage()->set(ObjectType::Post, $id, $key, 'through acf'));
        self::assertSame('field_tmswp_acf_contract_single', get_post_meta($id, '_' . $key, true));
        self::assertSame('through acf', get_field($key, $id));
    }

    protected function storage(): MetaStorageInterface
    {
        $storage = self::themeKernel()->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);

        return $storage;
    }

    protected function objectType(): ObjectType
    {
        return ObjectType::Post;
    }

    protected function createObject(): int
    {
        return self::createPost('tmswp_acf_post');
    }

    /**
     * setAll()で複数の行を書くキー(rows・slashed_rows・deleted、setAll()のnullも確かめるnull)は、ACFのフィールドでないキーに
     * する。ACFのフィールドは値を1つだけ持ち、AcfMetaStorageはACFのフィールドのキーへのsetAll()を設定の誤りにするため。
     * ほかのキーはACFのフィールドである。
     */
    protected function key(string $name): string
    {
        return \in_array($name, ['rows', 'slashed_rows', 'deleted', 'null'], true) ? 'tmswp_acf_contract_multi_' . $name : 'tmswp_acf_contract_' . $name;
    }
}
