<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Support;

use ACF_Data;

/**
 * ACFのstore(acf_register_store()で登録したACF_Data。ローカルのフィールドとグループ、値とフィールドのキャッシュ等)の
 * dataとaliasesを、テストの開始時の状態に戻す統合テスト用のtrait。テストの中でstoreのエントリを足しても、消しても
 * (`acf_get_store('values')->reset()`等)、後続のテストは開始時と同じstoreを受け取る。
 *
 * 使うテストは、set_upでsnapshotAcfStores()を、tear_downでrestoreAcfStores()を呼ぶ。
 */
trait RestoresAcfStores
{
    /** @var array<string, array{data: array<mixed>, aliases: array<mixed>}> storeの名前 => 開始時のdataとaliases */
    private array $acfStoresAtStart = [];

    /** 登録済みのすべてのACFのstoreの、dataとaliasesを控える。 */
    private function snapshotAcfStores(): void
    {
        $this->acfStoresAtStart = [];

        foreach (self::acfStoreNames() as $name) {
            $store = self::acfStore($name);
            $this->acfStoresAtStart[$name] = ['data' => $store->data, 'aliases' => $store->aliases];
        }
    }

    /**
     * 控えたstoreのdataとaliasesを、控えたときの値に戻す。テストの中で登録されたstore(戻す先の状態が無い)は、登録を
     * 外す。控えたstoreをすべて戻し、増えたstoreを外した後で、増えたことと消えたstoreを失敗として報告する(報告の
     * 例外で、ほかのstoreの復元が飛ばされないようにするため)。
     */
    private function restoreAcfStores(): void
    {
        $added = array_values(array_diff(self::acfStoreNames(), array_keys($this->acfStoresAtStart)));
        $missing = [];

        foreach ($this->acfStoresAtStart as $name => $snapshot) {
            $store = acf_get_store($name);

            if (!$store instanceof ACF_Data) {
                $missing[] = $name;
                continue;
            }

            $store->data = $snapshot['data'];
            $store->aliases = $snapshot['aliases'];
        }

        // ACF 6.8.10は登録を外す関数を持たず、acf_register_store()は$GLOBALS['acf_stores']に置くだけのため、そこから外す
        if ($added !== []) {
            $stores = $GLOBALS['acf_stores'];
            self::assertIsArray($stores);
            $GLOBALS['acf_stores'] = array_diff_key($stores, array_flip($added));
        }

        self::assertSame([], $added, 'ACF stores registered during the test were unregistered; they cannot be restored to the state at the start');
        self::assertSame([], $missing, 'ACF stores registered at the start of the test were removed during the test');
    }

    /** @return list<string> */
    private static function acfStoreNames(): array
    {
        $stores = $GLOBALS['acf_stores'] ?? null;
        self::assertIsArray($stores, 'ACF stores are not registered; the integration tests need the active ACF');
        $names = [];

        foreach (array_keys($stores) as $name) {
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }

    private static function acfStore(string $name): ACF_Data
    {
        $store = acf_get_store($name);
        self::assertInstanceOf(ACF_Data::class, $store);

        return $store;
    }
}
