<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Integration;

use ACF;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use stdClass;
use TMSWP\AcfBridge\AcfMetaStorage;
use TMSWP\AcfBridge\BridgeConfig;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Generate\GeneratedFile;
use TMSWP\AcfBridge\Generate\Generator;
use TMSWP\AcfBridge\Generate\GeneratorContext;
use TMSWP\AcfBridge\Json\AcfJsonRepository;
use TMSWP\AcfBridge\Json\FieldGroup;
use TMSWP\Core\Exception\InvalidArgumentException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Core\Meta\ObjectType;
use TMSWP\Core\Meta\WpMetaStorage;
use TMSWP\Core\Post\PostType;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * 有効化した無料版ACF(.wp-env.jsonで導入)の実物で、AcfMetaStorage(MetaBagの委譲)とAcfFieldAccess(生成クラスの
 * 読み書きの基盤)の実行時の振る舞いを確かめる。
 *
 * fixtureのテーマのacf-jsonは、tmswp_acf_postのprice(default_valueは'0')・subtitleと、契約テスト用の
 * tmswp_acf_contract_*を持つ。エンティティAcfStoredPost(#[UseMetaStorage(AcfMetaStorage::class)])は、ACFの
 * フィールドのprice(既定値'0')と、ACFのフィールドでないtmswp_acf_stored_note(既定値'none')を宣言する。
 *
 * AcfFieldAccessのヘルパは、実行時に宣言するプローブのクラス(AcfAccessProbe)で呼ぶ。プローブを置く名前空間で、
 * 形の不一致の扱いを決めるKernel(fixtureのテーマはdebug、本番相当のKernelはdebugでない)が決まる。プローブは、
 * set_up()がACFのローカルのフィールドグループとして登録するtextのフィールド(keyは`field_{名前}`)を、生成アクセサと
 * 同じくkeyで読み書きする。textのフィールドはフォーマットで値を変えないため、get_field()は保存したメタをそのまま返す。
 */
final class AcfBridgeIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const PRICE = 'price';

    private const NOTE = 'tmswp_acf_stored_note';

    /** fixtureのacf-json(group_tmswp_acf_contract.json)のtextフィールド */
    private const CONTRACT_KEY = 'tmswp_acf_contract_single';

    /** プローブが読み書きするtextのフィールドの名前(set_up()がACFに登録する) */
    private const PROBE_FIELDS = [
        'tmswp_probe_absent',
        'tmswp_probe_array',
        'tmswp_probe_date',
        'tmswp_probe_datetime',
        'tmswp_probe_dst',
        'tmswp_probe_dst_part',
        'tmswp_probe_empty',
        'tmswp_probe_exponent',
        'tmswp_probe_float',
        'tmswp_probe_gallery',
        'tmswp_probe_group',
        'tmswp_probe_ids',
        'tmswp_probe_int',
        'tmswp_probe_list',
        'tmswp_probe_nul',
        'tmswp_probe_overflow',
        'tmswp_probe_text',
        'tmswp_probe_word',
    ];

    private const FIELDS_CLASS = self::THEME_NAMESPACE . '\\Acf\\TmswpAcfPostFields';

    public function set_up(): void
    {
        parent::set_up();
        self::registerLocalFieldGroup('group_tmswp_probe', array_map(
            static fn(string $name): array => ['key' => 'field_' . $name, 'name' => $name, 'label' => $name, 'type' => 'text'],
            self::PROBE_FIELDS,
        ));
    }

    public function tear_down(): void
    {
        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * CIの環境の前提(無料版のACF)。ACF Proで走らせるcomposer test:integration:acf-proは、このグループを外す。
     *
     * @group acf-free
     */
    public function testFreeAcfIsActive(): void
    {
        self::assertInstanceOf(ACF::class, acf());
        self::assertFalse(acf_is_pro());
    }

    public function testUseMetaStorageEntityReadsAndWritesThroughAcf(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Acf\\AcfStoredPost', PostType::class);
        self::assertTrue(post_type_exists('tmswp_acf_post'), 'fixture post type が登録されていません: tmswp_acf_post');
        $postId = self::createPost('tmswp_acf_post');
        $bag = $postClass::meta($postId);

        self::assertFalse($bag->has('price'));
        self::assertTrue($bag->set('price', '1200'));
        self::assertTrue($bag->has('price'));
        self::assertSame('1200', $bag->string('price'));
        // ACF の保存規約(update_field)で投稿メタに書かれ、ACF の読み出し(get_field)でも同じ値になる
        self::assertSame('1200', get_post_meta($postId, 'price', true));
        self::assertSame('1200', get_field('price', $postId, false));
    }

    /**
     * v0.1.0-T4で外したテスト(台帳A5-T4)。ACFのフィールドのキーをMetaBagで削除すると、値と参照のメタが消え、has()は
     * falseになる。ACFの読み出し(get_field())は既定値の'0'に戻る。
     */
    public function testUseMetaStorageEntityDeletesThroughAcf(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Acf\\AcfStoredPost', PostType::class);
        $postId = self::createPost('tmswp_acf_post');
        $bag = $postClass::meta($postId);
        self::assertTrue($bag->set('price', '1200'));

        self::assertTrue($bag->delete('price'));
        self::assertFalse($bag->has('price'));
        self::assertNull(get_metadata_raw('post', $postId, 'price'));
        self::assertNull(get_metadata_raw('post', $postId, '_price'));
        self::assertSame('0', get_field('price', $postId, false));
    }

    /**
     * ACFのフィールドのキー(price)とACFのフィールドでないキー(note)のどちらも、MetaBagのhas()は保存したメタの行の
     * 有無で判定し、ACFのdefault_valueや#[MetaSchema]の既定値(register_post_metaが生の読み出しにも返す)を
     * 「ある」としない。削除は行を消してtrueを返し、2回目はfalseになる。ACFのフィールドのキーだけが参照のメタを持つ。
     *
     * @dataProvider acfStoredPostKeys
     */
    public function testMetaBagHasAndDeleteFollowTheStoredRows(string $key, string $default, bool $acfField): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Acf\\AcfStoredPost', PostType::class);
        $postId = self::createPost('tmswp_acf_post');
        $bag = $postClass::meta($postId);

        // 確かめ方の確認: 値を保存していなくても、WordPressの読み出しは既定値を返す
        self::assertSame($default, get_post_meta($postId, $key, true));
        self::assertSame([$default], get_post_meta($postId, $key));
        self::assertFalse($bag->has($key));
        self::assertSame($default, $bag->string($key));

        self::assertTrue($bag->set($key, 'stored'));
        self::assertTrue($bag->has($key));
        self::assertSame('stored', $bag->string($key));
        self::assertSame($acfField ? 'field_tmswp_acf_post_price' : null, get_metadata_raw('post', $postId, '_' . $key, true));

        self::assertTrue($bag->delete($key));
        self::assertFalse($bag->has($key));
        self::assertNull(get_metadata_raw('post', $postId, $key));
        self::assertNull(get_metadata_raw('post', $postId, '_' . $key));
        self::assertFalse($bag->delete($key));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function acfStoredPostKeys(): iterable
    {
        yield 'ACFのフィールド' => [self::PRICE, '0', true];
        yield 'ACFのフィールドでないキー' => [self::NOTE, 'none', false];
    }

    /**
     * タームとユーザーのメタは、ACFのpost_id(term_{ID}・user_{ID})で読み書きされる。ACFのフィールドのキーとACFの
     * フィールドでないキーのどちらも、AcfMetaStorageで書いた値をACFが読め、削除するとhas()がfalseになる。
     */
    public function testTermAndUserMetaUseTheAcfObjectIds(): void
    {
        $storage = self::themeKernel()->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);
        $term = wp_insert_term('ACF bridge term', 'category');
        self::assertIsArray($term);
        $userId = self::factory()->user->create();
        self::assertIsInt($userId);
        $objects = [[ObjectType::Term, (int) $term['term_id'], 'term_' . $term['term_id']], [ObjectType::User, $userId, "user_{$userId}"]];

        foreach ($objects as [$type, $id, $acfId]) {
            foreach (['tmswp_acf_contract_single', 'tmswp_acf_not_a_field'] as $key) {
                self::assertFalse($storage->has($type, $id, $key), "{$acfId} {$key}");
                self::assertTrue($storage->set($type, $id, $key, "value of {$key}"), "{$acfId} {$key}");
                self::assertSame("value of {$key}", get_field($key, $acfId, false), "{$acfId} {$key}");
                self::assertSame("value of {$key}", get_metadata($type->metaType(), $id, $key, true), "{$acfId} {$key}");
                self::assertTrue($storage->delete($type, $id, $key), "{$acfId} {$key}");
                self::assertFalse($storage->has($type, $id, $key), "{$acfId} {$key}");
            }
        }
    }

    /**
     * getAll()とsetAll()は、WpMetaStorageと同じくメタの行を読み書きする。set()で保存した配列(複数選択の値等)は1行で、
     * getAll()はその行を1つの要素として返す。ACFのフィールドでないキーのsetAll()は、値ごとに行を作る。
     */
    public function testGetAllAndSetAllWorkOnMetaRows(): void
    {
        $storage = self::acfMetaStorage();
        $postId = self::createPost('tmswp_acf_post');

        foreach (['tmswp_acf_contract_array', 'tmswp_acf_not_a_field'] as $key) {
            self::assertTrue($storage->set(ObjectType::Post, $postId, $key, ['red', 'blue']), $key);
            self::assertSame([['red', 'blue']], $storage->getAll(ObjectType::Post, $postId, $key), $key);
            self::assertSame([['red', 'blue']], get_post_meta($postId, $key), $key);
        }

        self::assertTrue($storage->setAll(ObjectType::Post, $postId, 'tmswp_acf_not_a_field', ['green', 'yellow']));
        self::assertSame(['green', 'yellow'], get_post_meta($postId, 'tmswp_acf_not_a_field'));
        self::assertSame(['green', 'yellow'], $storage->getAll(ObjectType::Post, $postId, 'tmswp_acf_not_a_field'));
    }

    /**
     * ACFのフィールドは値を1つだけ持つため、ACFのフィールドのキーへのsetAll()は、フィールドの型にかかわらず設定の誤りとして
     * AcfBridgeRuntimeExceptionになり、メタを変えない。
     *
     * @dataProvider acfFieldKeysOfAnyType
     */
    public function testSetAllRejectsAcfFieldKeys(string $key, string $fieldKey): void
    {
        $storage = self::acfMetaStorage();
        $postId = self::createPost('tmswp_acf_post');
        add_post_meta($postId, $key, 'kept');

        try {
            $storage->setAll(ObjectType::Post, $postId, $key, ['one', 'two']);
            self::fail('AcfBridgeRuntimeException was not thrown');
        } catch (AcfBridgeRuntimeException $exception) {
            self::assertSame("AcfMetaStorage cannot setAll() meta '{$key}': it is the ACF field {$fieldKey}, which holds a single value, so it cannot store multiple rows. Use set() instead; if the entity declares the key with #[MetaSchema(single: false)], declare it as a single value", $exception->getMessage());
        }

        self::assertSame(['kept'], get_post_meta($postId, $key));
    }

    /** @return iterable<string, array{string, string}> */
    public static function acfFieldKeysOfAnyType(): iterable
    {
        yield 'text' => ['tmswp_acf_contract_array', 'field_tmswp_acf_contract_array'];
        yield 'number' => ['stock', 'field_tmswp_acf_post_stock'];
        yield 'group(扱えない型)' => ['slashed_group', 'field_tmswp_acf_post_slashed_group'];
    }

    /**
     * set()・setAll()・delete()の後は、ACFのフィールドのキーでもACFのフィールドでないキーでも、ACFの値のキャッシュ
     * (get_field()が使う)は古い値を返さない。
     */
    public function testWritesOutsideAcfFlushTheAcfValueCache(): void
    {
        $storage = self::themeKernel()->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);
        $postId = self::createPost('tmswp_acf_post');

        foreach ([self::CONTRACT_KEY, 'tmswp_acf_not_a_field'] as $key) {
            $storage->set(ObjectType::Post, $postId, $key, 'first');
            self::assertSame('first', get_field($key, $postId, false), $key);
            $storage->set(ObjectType::Post, $postId, $key, 'second');
            self::assertSame('second', get_field($key, $postId, false), $key);
        }

        $storage->setAll(ObjectType::Post, $postId, 'tmswp_acf_not_a_field', ['rows']);
        self::assertSame('rows', get_field('tmswp_acf_not_a_field', $postId, false));

        $storage->set(ObjectType::Post, $postId, 'tmswp_acf_not_a_field', 'third');
        self::assertSame('third', get_field('tmswp_acf_not_a_field', $postId, false));
        $storage->delete(ObjectType::Post, $postId, 'tmswp_acf_not_a_field');
        self::assertNull(get_field('tmswp_acf_not_a_field', $postId, false));
    }

    /**
     * 生成クラスの基盤(AcfFieldAccess)のhas()は、保存した値の有無で判定し、フィールドのdefault_value(value()が返す)を
     * 「ある」としない。optionsのpost_idでは、ACFが値を保存するoptionの有無で判定する。
     */
    public function testAccessorHasFollowsTheStoredValue(): void
    {
        $probeClass = self::declareProbe(self::THEME_NAMESPACE . '\\Probe');
        $access = $probeClass::of(self::createPost('tmswp_acf_post'));

        self::assertFalse($access->has(self::PRICE));
        self::assertSame('0', $access->value(self::PRICE));
        self::assertTrue($access->update(self::PRICE, '1500'));
        self::assertTrue($access->has(self::PRICE));
        self::assertTrue($access->delete(self::PRICE));
        self::assertFalse($access->has(self::PRICE));

        $options = $probeClass::of('options');
        $key = 'tmswp_acf_contract_single';
        self::assertFalse($options->has($key));
        self::assertTrue($options->update($key, 'site wide'));
        self::assertTrue($options->has($key));
        self::assertSame('site wide', get_option("options_{$key}"));
        self::assertTrue($options->delete($key));
        self::assertFalse($options->has($key));
    }

    /**
     * 生成クラスのsetter(traitのacfUpdate()からupdate())は、値をslashしてupdate_field()に渡す。バックスラッシュ・`\"`・
     * `\n`の文字を含む値が、textと、groupのサブフィールドと、optionsで、そのまま往復する(ACFはメタでも
     * optionsでもwp_unslash()するため、slashしないとバックスラッシュが消える)。
     */
    public function testSettersKeepBackslashes(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        $text = 'C:\Users\tmswp say \"hi\" line\nnext';
        $group = ['json' => '{"k":"v\\\\n","q":"\""}', 'path' => '\\\\server\share\n'];
        $of = [self::THEME_NAMESPACE . '\\Acf\\TmswpAcfPostFields', 'of'];
        self::assertIsCallable($of);
        $fields = $of($postId);
        self::assertIsObject($fields);
        $setText = [$fields, 'setSlashedText'];
        $setGroup = [$fields, 'setSlashedGroup'];
        self::assertIsCallable($setText);
        self::assertIsCallable($setGroup);

        $setText($text);
        $setGroup($group);

        self::assertSame($text, get_post_meta($postId, 'slashed_text', true));
        self::assertSame($text, get_field('slashed_text', $postId));
        self::assertSame($group['json'], get_post_meta($postId, 'slashed_group_json', true));
        self::assertSame($group['path'], get_post_meta($postId, 'slashed_group_path', true));
        self::assertSame($group, get_field('slashed_group', $postId));

        $options = self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of('options');
        self::assertTrue($options->update('tmswp_acf_contract_single', $text));
        self::assertSame($text, get_option('options_tmswp_acf_contract_single'));
    }

    /**
     * ACFの未設定の値(保存していない・''・false)と、groupの空の配列は、型ごとの未設定の値に正規化され、例外にも
     * ログにもならない(debugのKernelのプローブで確かめる)。数値の文字列は、整数はint、小数・指数はfloatになる。
     */
    public function testUnsetValuesAreNormalized(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        update_post_meta($postId, 'tmswp_probe_empty', '');
        update_post_meta($postId, 'tmswp_probe_group', []);
        update_post_meta($postId, 'tmswp_probe_int', '12');
        update_post_meta($postId, 'tmswp_probe_float', '1.5');
        update_post_meta($postId, 'tmswp_probe_exponent', '1e-3');
        $probe = self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of($postId);

        foreach (['tmswp_probe_absent', 'tmswp_probe_empty'] as $name) {
            self::assertSame('', $probe->string($name), $name);
            self::assertNull($probe->nullableString($name), $name);
            self::assertNull($probe->number($name), $name);
            self::assertNull($probe->int($name), $name);
            self::assertFalse($probe->bool($name), $name);
            self::assertSame([], $probe->rows($name), $name);
            self::assertSame([], $probe->intList($name), $name);
            self::assertNull($probe->group($name), $name);
            self::assertNull($probe->image($name), $name);
            self::assertSame([], $probe->imageList($name), $name);
            self::assertNull($probe->dateTime($name, 'Ymd'), $name);
        }

        self::assertNull($probe->group('tmswp_probe_group'));
        self::assertSame(12, $probe->number('tmswp_probe_int'));
        self::assertSame(12, $probe->int('tmswp_probe_int'));
        self::assertSame(1.5, $probe->number('tmswp_probe_float'));
        self::assertSame(0.001, $probe->number('tmswp_probe_exponent'));
    }

    /** debugのKernel(fixtureのテーマ)では、形の不一致はフィールドと型を示すAcfBridgeRuntimeExceptionになる。 */
    public function testShapeMismatchThrowsInDebug(): void
    {
        self::assertTrue(self::themeKernel()->config()->debug);
        $postId = self::createPost('tmswp_acf_post');
        update_post_meta($postId, 'tmswp_probe_array', ['not' => 'a string']);
        update_post_meta($postId, 'tmswp_probe_word', 'abc');
        update_post_meta($postId, 'tmswp_probe_float', '1.5');
        update_post_meta($postId, 'tmswp_probe_list', ['a', 'b']);
        $probeClass = self::declareProbe(self::THEME_NAMESPACE . '\\Probe');
        $probe = $probeClass::of($postId);
        $cases = [
            'string' => [static fn(): string => $probe->string('tmswp_probe_array'), "'tmswp_probe_array'", 'expected string, got array'],
            'number' => [static fn(): int|float|null => $probe->number('tmswp_probe_word'), "'tmswp_probe_word'", 'expected number, got string'],
            'int' => [static fn(): ?int => $probe->int('tmswp_probe_float'), "'tmswp_probe_float'", 'expected int, got float'],
            'group' => [static fn(): ?array => $probe->group('tmswp_probe_list'), "'tmswp_probe_list'", 'expected array with string keys, got list'],
            'rows' => [static fn(): array => $probe->rows('tmswp_probe_array'), "'tmswp_probe_array'", 'expected list, got array with string keys'],
            'image' => [static fn(): ?array => $probe->image('tmswp_probe_array'), "'tmswp_probe_array'", 'expected AcfImage, got array of another shape'],
        ];

        foreach ($cases as $name => [$read, $field, $types]) {
            try {
                $read();
                self::fail("AcfBridgeRuntimeException was not thrown for {$name}");
            } catch (AcfBridgeRuntimeException $exception) {
                self::assertSame("ACF field {$field} of {$postId} has an unexpected value: {$types} ({$probeClass})", $exception->getMessage(), $name);
            }
        }
    }

    /**
     * 本番のKernelでは、形の不一致をそのKernelのロガーにerrorで記録し、未設定の値を返す。記録はフィールド・post_id・
     * 型だけを含み、値そのものは含まない。
     */
    public function testShapeMismatchIsLoggedAndNormalizedInProduction(): void
    {
        $logger = new RecordingLogger();
        $probeClass = self::declareProbe($this->productionKernel('ShapeMismatch', $logger)->config()->appNamespace . '\\Probe');
        $postId = self::createPost('tmswp_acf_post');
        update_post_meta($postId, 'tmswp_probe_array', ['secret' => 'hidden']);
        $probe = $probeClass::of($postId);

        self::assertSame('', $probe->string('tmswp_probe_array'));
        self::assertSame([], $probe->rows('tmswp_probe_array'));
        self::assertNull($probe->image('tmswp_probe_array'));

        self::assertSame([
            ['error', 'tmswp_probe_array', 'string', 'array'],
            ['error', 'tmswp_probe_array', 'list', 'array with string keys'],
            ['error', 'tmswp_probe_array', 'AcfImage', 'array of another shape'],
        ], array_map(static fn(array $record): array => [$record['level'], $record['context']['field'], $record['context']['expected'], $record['context']['actual']], $logger->records));

        foreach ($logger->records as $record) {
            self::assertSame('tmswp.acf: ACF field {field} of {acfId} has an unexpected value: expected {expected}, got {actual} ({class})', $record['message']);
            self::assertSame((string) $postId, $record['context']['acfId']);
            self::assertSame($probeClass, $record['context']['class']);
            self::assertStringNotContainsString('secret', (string) json_encode($record['context']));
            self::assertStringNotContainsString('hidden', (string) json_encode($record['context']));
        }
    }

    /**
     * listは要素ごとに検査する(galleryの1枚の不正で全体を捨てない)。本番では不一致の要素だけを記録して除き、
     * debugでは不一致の要素を示す例外になる。ACFが返す画像の形(urlやiconがfalse、width・heightが無い、sizesが空)は
     * 不一致にしない。
     */
    public function testListsAreCheckedPerElement(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        $svg = self::image(1);
        $svg['url'] = false;
        $svg['icon'] = false;
        $svg['sizes'] = [];
        unset($svg['width'], $svg['height']);
        $broken = ['ID' => 'not an int'] + self::image(2);
        update_post_meta($postId, 'tmswp_probe_gallery', [self::image(3), $broken, $svg]);
        update_post_meta($postId, 'tmswp_probe_ids', [4, '5', 'six', 7]);

        $logger = new RecordingLogger();
        $production = self::declareProbe($this->productionKernel('ListElements', $logger)->config()->appNamespace . '\\Probe')::of($postId);

        self::assertSame([self::image(3), $svg], $production->imageList('tmswp_probe_gallery'));
        self::assertSame([4, 5, 7], $production->intList('tmswp_probe_ids'));
        self::assertSame([['tmswp_probe_gallery[1]', 'AcfImage', 'array of another shape'], ['tmswp_probe_ids[2]', 'int', 'string']], array_map(
            static fn(array $record): array => [$record['context']['field'], $record['context']['expected'], $record['context']['actual']],
            $logger->records,
        ));

        $debugClass = self::declareProbe(self::THEME_NAMESPACE . '\\Probe');
        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("ACF field 'tmswp_probe_gallery[1]' of {$postId} has an unexpected value: expected AcfImage, got array of another shape ({$debugClass})");

        $debugClass::of($postId)->imageList('tmswp_probe_gallery');
    }

    /**
     * objectのlistの生成されたgetter(relationship・post_object・taxonomy・userの複数。return_format=object)は、要素ごとに
     * クラスを検査する。実物のACFのacf/format_valueのフィルタで不正な要素(文字列と別のクラスのobject)を混ぜると、本番では
     * 正常な要素を残して不正な要素ごとに記録し、debugでは不正な要素を示す例外になる。本番は、同じacf-jsonから本番の
     * Kernelの名前空間へ生成したクラスで、debugはfixtureのテーマの生成物(TmswpAcfPostFields)で確かめる。
     */
    public function testGeneratedObjectListGettersCheckEachElement(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        $relatedId = self::createPost('post');
        $term = wp_insert_term('ACF bridge object list', 'category');
        self::assertIsArray($term);
        $userId = self::factory()->user->create();
        self::assertIsInt($userId);
        $getters = [
            'relatedPosts' => ['related_posts', WP_Post::class, $relatedId, [$relatedId]],
            'pickedPosts' => ['picked_posts', WP_Post::class, $relatedId, [$relatedId]],
            'pickedTerms' => ['picked_terms', WP_Term::class, (int) $term['term_id'], [(int) $term['term_id']]],
            'pickedUsers' => ['picked_users', WP_User::class, $userId, [$userId]],
        ];
        $inject = static fn(mixed $value): mixed => \is_array($value) ? [...$value, 'not an object', new stdClass()] : $value;

        foreach ($getters as [$field, , , $ids]) {
            self::assertTrue((bool) update_field($field, $ids, $postId), $field);
            add_filter("acf/format_value/name={$field}", $inject);
        }

        $logger = new RecordingLogger();
        $production = self::fieldsOf($this->generateThemeFieldsInto($this->productionKernel('ObjectLists', $logger)->config()->appNamespace . '\\Acf'), $postId);

        foreach ($getters as $getter => [$field, $class, $id]) {
            $read = [$production, $getter];
            self::assertIsCallable($read);
            $items = $read();
            self::assertIsArray($items, $getter);
            self::assertCount(1, $items, $getter);
            self::assertInstanceOf($class, $items[0], $getter);
            $itemId = $items[0] instanceof WP_Term ? $items[0]->term_id : $items[0]->ID;
            self::assertSame($id, $itemId, $getter);
        }

        $expectedRecords = [];

        foreach ($getters as [$field, $class]) {
            $expectedRecords[] = ['error', "{$field}[1]", $class, 'string'];
            $expectedRecords[] = ['error', "{$field}[2]", $class, 'stdClass'];
        }

        self::assertSame($expectedRecords, array_map(
            static fn(array $record): array => [$record['level'], $record['context']['field'], $record['context']['expected'], $record['context']['actual']],
            $logger->records,
        ));

        $debugClass = self::THEME_NAMESPACE . '\\Acf\\TmswpAcfPostFields';
        $debug = self::fieldsOf($debugClass, $postId);

        foreach ($getters as $getter => [$field, $class]) {
            $read = [$debug, $getter];
            self::assertIsCallable($read);

            try {
                $read();
                self::fail("AcfBridgeRuntimeException was not thrown for {$getter}");
            } catch (AcfBridgeRuntimeException $exception) {
                self::assertSame("ACF field '{$field}[1]' of {$postId} has an unexpected value: expected {$class}, got string ({$debugClass})", $exception->getMessage(), $getter);
            }
        }
    }

    /**
     * 日時のsetterは、DateTimeInterfaceをwp_timezone()の日時に変換して保存し、getter(*AsDateTime)はwp_timezone()で
     * 読むため、同じ時刻として往復する。
     */
    public function testDateTimeRoundTripsInTheSiteTimezone(): void
    {
        update_option('timezone_string', 'Asia/Tokyo');
        $postId = self::createPost('tmswp_acf_post');
        $probe = self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of($postId);
        $utc = new DateTimeImmutable('2024-01-01 15:30:00', new DateTimeZone('UTC'));

        $probe->setDate('tmswp_probe_datetime', $utc, 'Y-m-d H:i:s');
        $probe->setDate('tmswp_probe_date', $utc, 'Ymd');

        self::assertSame('2024-01-02 00:30:00', get_post_meta($postId, 'tmswp_probe_datetime', true));
        self::assertSame('20240102', get_post_meta($postId, 'tmswp_probe_date', true));
        $read = $probe->dateTime('tmswp_probe_datetime', 'Y-m-d H:i:s');
        self::assertInstanceOf(DateTimeImmutable::class, $read);
        self::assertSame($utc->getTimestamp(), $read->getTimestamp());
        self::assertSame('Asia/Tokyo', $read->getTimezone()->getName());
        self::assertSame('2024-01-02 00:00:00 +09:00', $probe->dateTime('tmswp_probe_date', 'Ymd')?->format('Y-m-d H:i:s P'));
    }

    /**
     * 書式に合わない日付と、範囲外の日付(13月等。createFromFormat()は繰り上げて受け付ける)は形の不一致になる。本番では
     * 記録してnullを返し、debugでは例外になる。
     */
    public function testInvalidOrOverflowingDatesAreShapeMismatches(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        update_post_meta($postId, 'tmswp_probe_overflow', '20241332');
        update_post_meta($postId, 'tmswp_probe_text', 'tomorrow');

        $logger = new RecordingLogger();
        $production = self::declareProbe($this->productionKernel('Dates', $logger)->config()->appNamespace . '\\Probe')::of($postId);

        self::assertNull($production->dateTime('tmswp_probe_overflow', 'Ymd'));
        self::assertNull($production->dateTime('tmswp_probe_text', 'Ymd'));
        self::assertSame(['tmswp_probe_overflow', 'tmswp_probe_text'], array_map(static fn(array $record): mixed => $record['context']['field'], $logger->records));

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("ACF field 'tmswp_probe_overflow' of {$postId} has an unexpected value: expected date string in the format 'Ymd', got string that does not match the format");

        self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of($postId)->dateTime('tmswp_probe_overflow', 'Ymd');
    }

    /**
     * 外側のACFの操作(update_field()・delete_field()による投稿Aへの書き込み・削除)のacf/update_value・acf/delete_valueの
     * 中から、投稿Bの同じ名前のACFのフィールドを、AcfMetaStorageとACFの直接の呼び出しで書く・消しても、AとBの値と
     * 参照のメタがどれも正しくなる。
     *
     * 外側がAcfMetaStorageの場合は、acf/validate_post_idがAをBへ解決し直すときも(レビューのR1の再現の条件)、
     * AcfMetaStorageはACFの書き込みの関数とフィルタを通らないため、Bへの操作が割り込まず、Aだけを書き換えてBは元の
     * ままになる。
     *
     * @dataProvider nestedOperations
     *
     * @param Closure(AcfMetaStorage, int): void $nested 投稿B(ID)への入れ子の操作
     */
    public function testNestedAcfOperationOnAnotherPostKeepsEveryValueAndReference(string $outer, Closure $nested, ?string $expectedB): void
    {
        $storage = self::acfMetaStorage();
        $key = self::CONTRACT_KEY;
        [$postA, $postB] = [self::createPost('tmswp_acf_post'), self::createPost('tmswp_acf_post')];
        self::assertTrue($storage->set(ObjectType::Post, $postA, $key, 'seed A'));
        self::assertTrue($storage->set(ObjectType::Post, $postB, $key, 'seed B'));
        $outerIsStorage = str_starts_with($outer, 'storage');
        $ran = false;
        $runNested = static function (mixed $postId) use ($storage, $nested, $postA, $postB, &$ran): void {
            if (!$ran && ($postId === $postA || $postId === $postB)) {
                $ran = true;
                $nested($storage, $postB);
            }
        };
        $onUpdate = static function (mixed $value, mixed $postId) use ($runNested): mixed {
            $runNested($postId);

            return $value;
        };
        $onDelete = static function (mixed $postId, mixed $name) use ($runNested, $key): void {
            if ($name === $key) {
                $runNested($postId);
            }
        };
        $redirect = static fn(mixed $acfPostId): mixed => $acfPostId === $postA ? $postB : $acfPostId;
        add_filter("acf/update_value/name={$key}", $onUpdate, 10, 2);
        add_action('acf/delete_value', $onDelete, 10, 2);

        if ($outerIsStorage) {
            add_filter('acf/validate_post_id', $redirect);
        }

        try {
            $result = match ($outer) {
                'update_field' => (bool) update_field($key, 'value A', $postA),
                'delete_field' => delete_field($key, $postA),
                'storage set' => $storage->set(ObjectType::Post, $postA, $key, 'value A'),
                'storage delete' => $storage->delete(ObjectType::Post, $postA, $key),
                default => self::fail("unknown outer operation {$outer}"),
            };
        } finally {
            remove_filter("acf/update_value/name={$key}", $onUpdate, 10);
            remove_action('acf/delete_value', $onDelete, 10);
            remove_filter('acf/validate_post_id', $redirect);
        }

        $deletedA = str_ends_with($outer, 'delete') || $outer === 'delete_field';
        self::assertTrue($result);
        self::assertSame(!$outerIsStorage, $ran);
        self::assertSame($deletedA ? [] : ['value A'], get_post_meta($postA, $key));
        self::assertSame($deletedA ? [] : ['field_tmswp_acf_contract_single'], get_post_meta($postA, '_' . $key));

        $b = $outerIsStorage ? 'seed B' : $expectedB;
        self::assertSame($b === null ? [] : [$b], get_post_meta($postB, $key));
        self::assertSame($b === null ? [] : ['field_tmswp_acf_contract_single'], get_post_meta($postB, '_' . $key));
        self::assertSame($b, get_field($key, $postB, false));
    }

    /** @return iterable<string, array{string, Closure(AcfMetaStorage, int): void, string|null}> */
    public static function nestedOperations(): iterable
    {
        $key = self::CONTRACT_KEY;
        $operations = [
            'AcfMetaStorageで書く' => [static function (AcfMetaStorage $storage, int $postB) use ($key): void {
                self::assertTrue($storage->set(ObjectType::Post, $postB, $key, 'from A'));
            }, 'from A'],
            'update_field()で書く' => [static function (AcfMetaStorage $storage, int $postB) use ($key): void {
                self::assertTrue((bool) update_field($key, 'direct', $postB));
            }, 'direct'],
            'AcfMetaStorageで消す' => [static function (AcfMetaStorage $storage, int $postB) use ($key): void {
                self::assertTrue($storage->delete(ObjectType::Post, $postB, $key));
            }, null],
            'delete_field()で消す' => [static function (AcfMetaStorage $storage, int $postB) use ($key): void {
                self::assertTrue(delete_field($key, $postB));
            }, null],
        ];

        foreach (['update_field', 'delete_field', 'storage set', 'storage delete'] as $outer) {
            foreach ($operations as $name => [$nested, $expectedB]) {
                yield "外側{$outer}・内側{$name}" => [$outer, $nested, $expectedB];
            }
        }
    }

    /**
     * field keyの形(field_...)のキーは、ACFが別の名前のフィールドに解決するが、AcfMetaStorageはそのキーのメタとして
     * 読み書きする。MetaBagの入口からのset・has・get・deleteの結果とデータベースのエラーが、WpMetaStorageと同じになる。
     */
    public function testFieldKeyShapedKeyIsStoredAsItsOwnMeta(): void
    {
        $postClass = self::fixtureClass(self::THEME_NAMESPACE . '\\Acf\\AcfStoredPost', PostType::class);
        $key = 'field_tmswp_acf_contract_single';
        $acfPost = self::createPost('tmswp_acf_post');
        $wpPost = self::createPost('tmswp_acf_post');
        $wp = new WpMetaStorage(self::themeKernel());
        $bag = $postClass::meta($acfPost);
        $value = 'A\B';

        self::assertSame($wp->set(ObjectType::Post, $wpPost, $key, $value), $bag->set($key, $value));
        self::assertSame($wp->has(ObjectType::Post, $wpPost, $key), $bag->has($key));
        self::assertSame($wp->get(ObjectType::Post, $wpPost, $key), $bag->get($key));
        self::assertSame($value, get_post_meta($acfPost, $key, true));
        self::assertSame([], get_post_meta($acfPost, self::CONTRACT_KEY));
        // ACFのフィールドとして扱わないため、参照のメタも書かない
        self::assertSame([], get_post_meta($acfPost, '_' . $key));

        foreach ([$bag->set(...), static fn(string $k, mixed $v): bool => $wp->set(ObjectType::Post, $wpPost, $k, $v)] as $set) {
            self::withBrokenQueries($key, 'INSERT|UPDATE', static function () use ($set, $key): void {
                try {
                    $set($key, 'broken');
                    self::fail('RuntimeException was not thrown');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());
                }
            });
        }

        self::assertSame($wp->delete(ObjectType::Post, $wpPost, $key), $bag->delete($key));
        self::assertSame($wp->has(ObjectType::Post, $wpPost, $key), $bag->has($key));
        self::assertSame($wp->delete(ObjectType::Post, $wpPost, $key), $bag->delete($key));
    }

    /**
     * ACFのフィールドのset()で、値を書いた後に参照のメタの書き込みがデータベースのエラーになっても、元の対象のACFの値の
     * キャッシュは消えており、get_field()は保存されたメタと同じ値を返す。
     */
    public function testAcfValueCacheIsFlushedWhenTheReferenceWriteFails(): void
    {
        $storage = self::acfMetaStorage();
        $key = self::CONTRACT_KEY;
        $postId = self::createPost('tmswp_acf_post');
        self::assertTrue($storage->set(ObjectType::Post, $postId, $key, 'seed'));
        self::assertSame('seed', get_field($key, $postId, false));
        delete_post_meta($postId, '_' . $key);

        self::withBrokenQueries("'_{$key}'", 'INSERT', static function () use ($storage, $postId, $key): void {
            try {
                $storage->set(ObjectType::Post, $postId, $key, 'new');
                self::fail('RuntimeException was not thrown');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());
            }
        });

        self::assertSame('new', get_post_meta($postId, $key, true));
        self::assertSame('new', get_field($key, $postId, false));
    }

    /**
     * AcfMetaStorageはACFのpost_idの解決(acf/validate_post_id)を使わず、呼び出しの対象(has()・get()が読む投稿)に書き、
     * その投稿のACFの値のキャッシュを消す。解決し直した先の投稿には書かない。
     */
    public function testSetWritesTheTargetPostEvenWhenAcfResolvesAnotherPostId(): void
    {
        $storage = self::acfMetaStorage();
        $key = self::CONTRACT_KEY;
        [$postId, $resolvedId] = [self::createPost('tmswp_acf_post'), self::createPost('tmswp_acf_post')];
        self::assertTrue($storage->set(ObjectType::Post, $postId, $key, 'seed'));
        self::assertSame('seed', get_field($key, $postId, false));
        $resolve = static fn(mixed $acfPostId): mixed => $acfPostId === $postId ? $resolvedId : $acfPostId;
        add_filter('acf/validate_post_id', $resolve);

        try {
            self::assertTrue($storage->set(ObjectType::Post, $postId, $key, 'new'));
        } finally {
            remove_filter('acf/validate_post_id', $resolve);
        }

        self::assertSame('new', get_post_meta($postId, $key, true));
        self::assertSame('new', get_field($key, $postId, false));
        self::assertSame([], get_post_meta($resolvedId, $key));
    }

    /**
     * AcfMetaStorageは参照のメタを書くため、ACFは書いた値をフィールドとして読み、型ごとに整えた値を返す(true_falseはbool)。
     * number・rangeに','を含む文字列は、ACFなら','を取り除いて保存するため、黙って違う形で保存せずにInvalidArgumentExceptionにする。
     */
    public function testAcfReadsTheWrittenValuesAsFields(): void
    {
        $storage = self::acfMetaStorage();
        $postId = self::createPost('tmswp_acf_post');

        self::assertTrue($storage->set(ObjectType::Post, $postId, 'featured', true));
        self::assertTrue($storage->set(ObjectType::Post, $postId, 'stock', 12));
        self::assertSame('field_tmswp_acf_post_featured', get_post_meta($postId, '_featured', true));
        self::assertTrue(get_field('featured', $postId));
        self::assertSame('12', get_field('stock', $postId, false));

        try {
            $storage->set(ObjectType::Post, $postId, 'stock', '1,200');
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("meta 'stock' is an ACF number field and cannot store a string with ','", $exception->getMessage());
        }

        self::assertSame('12', get_post_meta($postId, 'stock', true));

        // ACFのフィールドのキーも、WpMetaStorageと同じくnullを保存しない
        try {
            $storage->set(ObjectType::Post, $postId, 'featured', null);
            self::fail('InvalidArgumentException was not thrown for null');
        } catch (InvalidArgumentException $exception) {
            self::assertSame("meta 'featured' cannot store null; use delete() to remove it", $exception->getMessage());
        }

        self::assertSame('1', get_post_meta($postId, 'featured', true));
    }

    /**
     * image・fileのフィールドは、単一の添付ファイルのID(正の整数のint)を保存する。ACFのupdate_valueと同じく、投稿では
     * post_parentが0の添付ファイルをその投稿に結び付け(ほかの投稿に結び付いた添付ファイルは変えない)、タームとユーザーでは
     * 結び付けない。参照のメタも書くため、ACFは値を画像・ファイルとして読む。正の整数のintでない値は
     * InvalidArgumentExceptionになり、値は残る。
     */
    public function testImageAndFileFieldsStoreASingleAttachmentId(): void
    {
        $storage = self::acfMetaStorage();
        $postId = self::createPost('tmswp_acf_post');
        $otherPostId = self::createPost('post');
        $image = self::createAttachment('image/jpeg', 0);
        $file = self::createAttachment('application/pdf', $otherPostId);

        self::assertTrue($storage->set(ObjectType::Post, $postId, 'main_image', $image));
        self::assertTrue($storage->set(ObjectType::Post, $postId, 'attachment_file', $file));
        self::assertSame((string) $image, get_post_meta($postId, 'main_image', true));
        self::assertSame('field_tmswp_acf_post_main_image', get_post_meta($postId, '_main_image', true));
        self::assertSame('field_tmswp_acf_post_attachment_file', get_post_meta($postId, '_attachment_file', true));
        self::assertSame($postId, get_post($image)?->post_parent);
        self::assertSame($otherPostId, get_post($file)?->post_parent);

        $readImage = get_field('main_image', $postId);
        self::assertIsArray($readImage);
        self::assertSame($image, $readImage['ID']);
        self::assertSame('image', $readImage['type']);
        $readFile = get_field('attachment_file', $postId);
        self::assertIsArray($readFile);
        self::assertSame($file, $readFile['ID']);

        // メッセージは、渡した値の型を示す。intは範囲の外の値そのものを示す(型だけでは何が誤りか分からないため)
        foreach (['数字の文字列' => [(string) $image, 'string'], 'ゼロ' => [0, '0'], '負の数' => [-1, '-1'], '配列' => [[$image], 'array']] as $name => [$invalid, $got]) {
            try {
                $storage->set(ObjectType::Post, $postId, 'main_image', $invalid);
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame("meta 'main_image' is an ACF image field and stores a single attachment ID; pass a positive int, got {$got}", $exception->getMessage(), $name);
            }
        }

        self::assertSame((string) $image, get_post_meta($postId, 'main_image', true));

        $term = wp_insert_term('ACF bridge attachment', 'category');
        self::assertIsArray($term);
        $userId = self::factory()->user->create();
        self::assertIsInt($userId);

        foreach ([[ObjectType::Term, (int) $term['term_id']], [ObjectType::User, $userId]] as [$type, $id]) {
            $attachment = self::createAttachment('image/png', 0);
            self::assertTrue($storage->set($type, $id, 'main_image', $attachment), $type->name);
            self::assertSame((string) $attachment, get_metadata($type->metaType(), $id, 'main_image', true), $type->name);
            self::assertSame('field_tmswp_acf_post_main_image', get_metadata($type->metaType(), $id, '_main_image', true), $type->name);
            self::assertSame(0, get_post($attachment)?->post_parent, $type->name);
        }

        self::assertTrue($storage->delete(ObjectType::Post, $postId, 'main_image'));
        self::assertSame([], get_post_meta($postId, '_main_image'));
    }

    /**
     * フィールドは、ACFのpost_idの解決(acf/validate_post_id)を通さずに、呼び出しの対象の参照のメタ(無ければ名前)で選ぶ。
     * 安定したフィルタで投稿AだけをBへ解決させても、Aの参照はAのフィールド(text)のkeyのまま残り、ACFはAの値を文字列と
     * して読み、Bは変わらない。Aに参照が無い初回の書き込みでも、Bの参照のフィールドを選ばない。
     */
    public function testFieldIsSelectedFromTheTargetWithoutAcfPostIdResolution(): void
    {
        $storage = self::acfMetaStorage();

        // 参照のメタが両方とも正しい状態から: AはtextのkeyA、Bはtrue_falseのkeyB(同じ名前のフィールド)
        $name = 'tmswp_acf_shared_value';
        $groups = self::sharedNameGroups('shared', $name, $name);
        array_map(acf_add_local_field_group(...), $groups);

        try {
            [$postA, $postB] = [self::createPost('tmswp_acf_post'), self::createPost('post')];
            self::assertTrue((bool) update_field('field_tmswp_acf_shared_text', 'seed A', $postA));
            self::assertTrue((bool) update_field('field_tmswp_acf_shared_flag', 1, $postB));
            self::assertTrue(self::setWhileAcfResolves($storage, $postA, $postB, $name, 'text-payload'));
            self::assertSame('field_tmswp_acf_shared_text', get_post_meta($postA, '_' . $name, true));
            self::assertSame('text-payload', get_field($name, $postA));
            self::assertSame('1', get_post_meta($postB, $name, true));
            self::assertSame('field_tmswp_acf_shared_flag', get_post_meta($postB, '_' . $name, true));
        } finally {
            foreach ($groups as $group) {
                acf_remove_local_field_group($group['key']);
            }
        }

        // Aに参照の無い初回の書き込み: Bの参照が指すフィールド(定義の名前は別)ではなく、名前で探したAのフィールドを使う
        $name = 'tmswp_acf_first_value';
        $groups = self::sharedNameGroups('first', $name, 'tmswp_acf_first_other');
        array_map(acf_add_local_field_group(...), $groups);

        try {
            [$postA, $postB] = [self::createPost('tmswp_acf_post'), self::createPost('post')];
            add_post_meta($postB, $name, '1');
            add_post_meta($postB, '_' . $name, 'field_tmswp_acf_first_flag');
            self::assertTrue(self::setWhileAcfResolves($storage, $postA, $postB, $name, 'first write'));
            self::assertSame('field_tmswp_acf_first_text', get_post_meta($postA, '_' . $name, true));
            self::assertSame('first write', get_field($name, $postA));
            self::assertSame(['1'], get_post_meta($postB, $name));
            self::assertSame(['field_tmswp_acf_first_flag'], get_post_meta($postB, '_' . $name));
        } finally {
            foreach ($groups as $group) {
                acf_remove_local_field_group($group['key']);
            }
        }
    }

    /**
     * textのフィールド(field_tmswp_acf_{$suffix}_text、名前$textName、tmswp_acf_post)と、true_falseのフィールド
     * (field_tmswp_acf_{$suffix}_flag、名前$flagName、post)のフィールドグループ。
     *
     * @return list<array{key: string, title: string, fields: list<array<string, string>>, location: list<list<array<string, string>>>}>
     */
    private static function sharedNameGroups(string $suffix, string $textName, string $flagName): array
    {
        return [
            ['key' => "group_tmswp_acf_{$suffix}_text", 'title' => 'Text', 'fields' => [['key' => "field_tmswp_acf_{$suffix}_text", 'name' => $textName, 'label' => 'Text', 'type' => 'text']], 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'tmswp_acf_post']]]],
            ['key' => "group_tmswp_acf_{$suffix}_flag", 'title' => 'Flag', 'fields' => [['key' => "field_tmswp_acf_{$suffix}_flag", 'name' => $flagName, 'label' => 'Flag', 'type' => 'true_false']], 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]]],
        ];
    }

    /**
     * 本番のKernel(debugでない)でも、set()・delete()・setAll()の中の、フィールドを選ぶための参照のメタの読み取りが
     * データベースのエラーになったら、名前による探索(同じ名前の別の定義、true_false)へ進まずにRuntimeExceptionにし、
     * Aの値と参照のメタを変えない。失敗した読み込みのキャッシュも消えており、読み直すとデータベースの値が返る。
     *
     * @dataProvider writeOperations
     *
     * @param Closure(AcfMetaStorage, int, string): mixed $write
     */
    public function testReferenceReadFailureStopsTheWriteInProduction(Closure $write): void
    {
        $logger = new RecordingLogger();
        $storage = new AcfMetaStorage($this->productionKernel('ReferenceRead', $logger));
        $name = 'tmswp_acf_guarded_value';
        $groups = self::sharedNameGroups('guarded', $name, $name);
        array_map(acf_add_local_field_group(...), $groups);

        try {
            $postId = self::createPost('tmswp_acf_post');
            self::assertTrue((bool) update_field('field_tmswp_acf_guarded_text', 'seed', $postId));
            // ACFの名前による探索は、最後に読み込んだ同じ名前のフィールドを返す。true_falseのフィールドを最後に読み込む
            acf_get_field('field_tmswp_acf_guarded_flag');
            self::assertSame('field_tmswp_acf_guarded_flag', acf_get_field($name)['key'] ?? null);
            wp_cache_delete($postId, 'post_meta');
            $reads = self::breakQueryOnce(self::wpdb()->postmeta, 'SELECT', static function () use ($write, $storage, $postId, $name): void {
                try {
                    $write($storage, $postId, $name);
                    self::fail('RuntimeException was not thrown');
                } catch (RuntimeException $exception) {
                    self::assertSame(RuntimeException::class, $exception::class);
                    self::assertStringStartsWith("failed to read post meta '_{$name}' for object {$postId} before writing '{$name}': ", $exception->getMessage());
                    self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());
                }
            });

            self::assertSame(1, $reads);
            self::assertSame([], $logger->records);
            self::assertSame(['seed'], get_post_meta($postId, $name));
            self::assertSame(['field_tmswp_acf_guarded_text'], get_post_meta($postId, '_' . $name));
            self::assertSame('seed', get_field($name, $postId));
        } finally {
            foreach ($groups as $group) {
                acf_remove_local_field_group($group['key']);
            }
        }
    }

    /** @return iterable<string, array{Closure(AcfMetaStorage, int, string): mixed}> */
    public static function writeOperations(): iterable
    {
        yield 'set' => [static fn(AcfMetaStorage $storage, int $postId, string $name): bool => $storage->set(ObjectType::Post, $postId, $name, 'new-text')];
        yield 'delete' => [static fn(AcfMetaStorage $storage, int $postId, string $name): bool => $storage->delete(ObjectType::Post, $postId, $name)];
        yield 'setAll' => [static fn(AcfMetaStorage $storage, int $postId, string $name): bool => $storage->setAll(ObjectType::Post, $postId, $name, ['one', 'two'])];
    }

    /**
     * $needleを含む$verbの文のうち最初の1つだけを、存在しないテーブルへのSQLに書き換え、データベースのエラーにした状態で
     * $callbackを実行する。書き換えた文の数(0か1)を返す。
     *
     * @param callable(): void $callback
     */
    private static function breakQueryOnce(string $needle, string $verb, callable $callback): int
    {
        $wpdb = self::wpdb();
        $broken = 0;
        $breakQuery = static function (string $query) use ($needle, $verb, $wpdb, &$broken): string {
            if ($broken === 0 && str_contains($query, $needle) && preg_match('/^\s*' . $verb . '\b/i', $query) === 1) {
                ++$broken;

                return 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET meta_value = 1';
            }

            return $query;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $breakQuery);

        try {
            $callback();
        } finally {
            remove_filter('query', $breakQuery);
            $wpdb->suppress_errors($suppressed);
        }

        return $broken;
    }

    /**
     * multipleでないselectは、ACFのupdate_valueが配列の要素をstrvalするため、配列(空の配列を含む)を
     * InvalidArgumentExceptionにし、値を残す。文字列と数値の選択肢は、そのまま保存する。
     */
    public function testSingleSelectRejectsArrays(): void
    {
        $storage = self::acfMetaStorage();
        $name = 'tmswp_acf_single_select';
        $group = ['key' => 'group_tmswp_acf_single_select', 'title' => 'Single select', 'fields' => [['key' => 'field_tmswp_acf_single_select', 'name' => $name, 'label' => 'Select', 'type' => 'select', 'multiple' => 0, 'choices' => ['red' => 'Red', '2' => 'Two']]], 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'tmswp_acf_post']]]];
        acf_add_local_field_group($group);

        try {
            $postId = self::createPost('tmswp_acf_post');
            self::assertTrue($storage->set(ObjectType::Post, $postId, $name, 2));
            self::assertSame('2', get_post_meta($postId, $name, true));
            self::assertTrue($storage->set(ObjectType::Post, $postId, $name, 'red'));
            self::assertSame('red', get_field($name, $postId, false));

            foreach (['空の配列' => [], '要素が1つ' => ['red'], '数値の配列' => [1, 2]] as $case => $value) {
                try {
                    $storage->set(ObjectType::Post, $postId, $name, $value);
                    self::fail("InvalidArgumentException was not thrown for {$case}");
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString("meta '{$name}' is an ACF select field without multiple and stores a single choice; pass a string or a number, not an array", $exception->getMessage(), $case);
                }
            }

            self::assertSame(['red'], get_post_meta($postId, $name));
        } finally {
            acf_remove_local_field_group($group['key']);
        }
    }

    /**
     * 添付ファイルの結び付けの更新(wp_update_post())がデータベースのエラーになると、RuntimeExceptionになる。値と参照の
     * メタは書かれており、ACFの値のキャッシュも消えている。acf/connect_attachment_to_postのフィルタがfalseを返して
     * 結び付けを中止するのは正常な結果で、例外にしない。
     */
    public function testAttachmentConnectionFailureThrowsAndAFilterMayStopIt(): void
    {
        $storage = self::acfMetaStorage();
        $postId = self::createPost('tmswp_acf_post');
        $image = self::createAttachment('image/jpeg', 0);

        self::withBrokenQueries("`ID` = {$image}", 'UPDATE', static function () use ($storage, $postId, $image): void {
            try {
                $storage->set(ObjectType::Post, $postId, 'main_image', $image);
                self::fail('RuntimeException was not thrown');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith("failed to connect attachment {$image} to post {$postId}: ", $exception->getMessage());
                self::assertStringContainsString('tmswp_missing_table', $exception->getMessage());
            }
        });

        clean_post_cache($image);
        self::assertSame(0, get_post($image)?->post_parent);
        self::assertSame((string) $image, get_post_meta($postId, 'main_image', true));
        self::assertSame('field_tmswp_acf_post_main_image', get_post_meta($postId, '_main_image', true));
        self::assertSame((string) $image, get_field('main_image', $postId, false));

        add_filter('acf/connect_attachment_to_post', '__return_false');

        try {
            $other = self::createAttachment('image/jpeg', 0);
            self::assertTrue($storage->set(ObjectType::Post, $postId, 'main_image', $other));
        } finally {
            remove_filter('acf/connect_attachment_to_post', '__return_false');
        }

        self::assertSame(0, get_post($other)?->post_parent);
        self::assertSame((string) $other, get_post_meta($postId, 'main_image', true));
    }

    /**
     * 生成アクセサはfield keyでACFを呼ぶ。参照のメタ(`_{名前}`)の無い投稿でも、getterはフィールドの型でフォーマットした
     * 値を返し(名前で呼ぶとACFはダミーのフィールドでrawを返す)、delete*()は値を消す(名前で呼ぶとACFは何も消さない)。
     * setterは未保存の投稿にも参照のメタにフィールドのkeyを書き、has*()は保存した値の有無を返す。
     */
    public function testGeneratedAccessorsCallAcfByFieldKey(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        $image = self::createAttachment('image/jpeg', $postId);
        update_post_meta($postId, 'main_image', $image);
        update_post_meta($postId, 'subtitle', 'stored without reference');
        self::assertSame([], get_post_meta($postId, '_main_image'));
        self::assertSame([], get_post_meta($postId, '_subtitle'));
        $fields = self::fieldsOf(self::FIELDS_CLASS, $postId);

        $readImage = self::callOn($fields, 'mainImage');
        self::assertIsArray($readImage);
        self::assertSame($image, $readImage['ID']);
        self::assertSame((string) $image, get_field('main_image', $postId));

        self::assertTrue(self::callOn($fields, 'hasSubtitle'));
        self::assertFalse(delete_field('subtitle', $postId));
        self::assertSame('stored without reference', get_post_meta($postId, 'subtitle', true));
        self::assertTrue(self::callOn($fields, 'deleteSubtitle'));
        self::assertSame([], get_post_meta($postId, 'subtitle'));
        self::assertFalse(self::callOn($fields, 'hasSubtitle'));

        $freshId = self::createPost('tmswp_acf_post');
        $fresh = self::fieldsOf(self::FIELDS_CLASS, $freshId);
        self::assertFalse(self::callOn($fresh, 'hasSubtitle'));
        self::callOn($fresh, 'setSubtitle', 'written by key');
        self::assertSame('written by key', get_post_meta($freshId, 'subtitle', true));
        self::assertSame('field_tmswp_acf_post_subtitle', get_post_meta($freshId, '_subtitle', true));
        self::assertSame('written by key', self::callOn($fresh, 'subtitle'));
        self::assertTrue(self::callOn($fresh, 'hasSubtitle'));
    }

    /**
     * keyのフィールドがACFに登録されていないこと(acf-jsonと生成クラスの食い違い)は設定の誤りで、debugでない本番の
     * Kernelでも、生成アクセサの読み書きは、ダミーのフィールドで別のメタを読み書きせずにAcfBridgeRuntimeExceptionになる。
     */
    public function testUnregisteredFieldKeyIsAConfigurationErrorEvenInProduction(): void
    {
        $logger = new RecordingLogger();
        $class = $this->generateFieldsInto($this->productionKernel('Unregistered', $logger)->config()->appNamespace . '\\Acf', FieldGroup::fromArray([
            'key' => 'group_tmswp_unregistered',
            'title' => 'Unregistered',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'tmswp_acf_post']]],
            'fields' => [['key' => 'field_tmswp_unregistered', 'name' => 'tmswp_unregistered', 'label' => 'Unregistered', 'type' => 'text']],
        ], 'acf-json/group_tmswp_unregistered.json'));
        $postId = self::createPost('tmswp_acf_post');
        $fields = self::fieldsOf($class, $postId);
        $calls = [
            'tmswpUnregistered' => ['acfString', []],
            'setTmswpUnregistered' => ['acfUpdate', ['value']],
            'hasTmswpUnregistered' => ['acfHas', []],
            'deleteTmswpUnregistered' => ['acfDelete', []],
        ];

        foreach ($calls as $method => [$helper, $arguments]) {
            try {
                self::callOn($fields, $method, ...$arguments);
                self::fail("AcfBridgeRuntimeException was not thrown for {$method}");
            } catch (AcfBridgeRuntimeException $exception) {
                self::assertStringStartsWith("ACF field key 'field_tmswp_unregistered' used by {$class}::{$helper}() is not registered in ACF.", $exception->getMessage(), $method);
            }
        }

        self::assertSame([], get_post_meta($postId, 'tmswp_unregistered'));
        self::assertSame([], get_post_meta($postId, 'field_tmswp_unregistered'));
        self::assertSame([], $logger->records);
    }

    /**
     * 生成したof()は、IDが0のWP_Post・0・負のIDをInvalidArgumentExceptionにする(ACFはpost_idの0を現在のループの投稿に
     * 置き換えるため、表示中の投稿を読み書きしない)。
     */
    public function testGeneratedFactoryRejectsIdsBelowOne(): void
    {
        $of = [self::FIELDS_CLASS, 'of'];
        self::assertIsCallable($of);

        foreach (['WP_Post with ID 0' => new WP_Post((object) ['ID' => 0]), 'zero' => 0, 'negative' => -1] as $name => $post) {
            try {
                $of($post);
                self::fail("InvalidArgumentException was not thrown for {$name}");
            } catch (InvalidArgumentException $exception) {
                self::assertSame('meta object ID must be a positive integer', $exception->getMessage(), $name);
            }
        }
    }

    /**
     * groupのgetterは、サブフィールドごとに型どおりに正規化する(実物の無料版ACFのgroup)。未保存のgroupは、ACFが
     * サブフィールドの未設定の値の配列を返すため、各サブフィールドの未設定の値になる。サブフィールドの形の不一致は、
     * debugでは位置(`slashed_group.json`)を示す例外になり、本番では記録して未設定の値にする。
     */
    public function testGroupGetterNormalizesEachSubField(): void
    {
        $unsaved = self::fieldsOf(self::FIELDS_CLASS, self::createPost('tmswp_acf_post'));
        self::assertSame(['json' => '', 'path' => ''], self::callOn($unsaved, 'slashedGroup'));

        $saved = self::fieldsOf(self::FIELDS_CLASS, self::createPost('tmswp_acf_post'));
        self::callOn($saved, 'setSlashedGroup', ['json' => '{"a":1}', 'path' => 12]);
        self::assertSame(['json' => '{"a":1}', 'path' => '12'], self::callOn($saved, 'slashedGroup'));

        $postId = self::createPost('tmswp_acf_post');
        update_post_meta($postId, 'slashed_group_json', ['secret' => 'hidden']);
        update_post_meta($postId, 'slashed_group_path', 'kept');

        try {
            self::callOn(self::fieldsOf(self::FIELDS_CLASS, $postId), 'slashedGroup');
            self::fail('AcfBridgeRuntimeException was not thrown');
        } catch (AcfBridgeRuntimeException $exception) {
            self::assertSame("ACF field 'slashed_group.json' of {$postId} has an unexpected value: expected string, got array (" . self::FIELDS_CLASS . ')', $exception->getMessage());
        }

        $logger = new RecordingLogger();
        $production = self::fieldsOf($this->generateThemeFieldsInto($this->productionKernel('Group', $logger)->config()->appNamespace . '\\Acf'), $postId);
        self::assertSame(['json' => '', 'path' => 'kept'], self::callOn($production, 'slashedGroup'));
        self::assertSame([['error', 'slashed_group.json', 'string', 'array']], array_map(
            static fn(array $record): array => [$record['level'], $record['context']['field'], $record['context']['expected'], $record['context']['actual']],
            $logger->records,
        ));
        self::assertStringNotContainsString('hidden', (string) json_encode($logger->records));
    }

    /**
     * traitの内部の名前と同じ名前のフィールド(date_time_raw_format・local_time_instants)から生成したクラスも、PHPで
     * 読み込め、日時のgetterとsetterが動く(traitは定数・staticメンバーを持たない)。
     */
    public function testGeneratedClassWithFieldsNamedLikeTraitInternalsLoadsAndWorks(): void
    {
        update_option('timezone_string', 'Europe/Berlin');
        $namespace = self::THEME_NAMESPACE . '\\Generated' . bin2hex(random_bytes(4));
        $outputDir = $this->createTempDirectory('acf-generated-names');
        $group = FieldGroup::fromArray([
            'key' => 'group_tmswp_internal_names',
            'title' => 'Internal names',
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'tmswp_acf_post']]],
            'fields' => [
                ['key' => 'field_tmswp_date_time_raw_format', 'name' => 'date_time_raw_format', 'label' => 'Format', 'type' => 'text'],
                ['key' => 'field_tmswp_local_time_instants', 'name' => 'local_time_instants', 'label' => 'Instants', 'type' => 'date_time_picker'],
            ],
        ], 'acf-json/group_tmswp_internal_names.json');
        self::registerLocalFieldGroup($group->key, $group->raw['fields'] ?? []);
        $report = (new Generator())->generate([$group], new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $outputDir, namespace: $namespace),
            optionsPagePostIds: [],
            sourceHash: 'internal-names',
        ));
        self::assertSame([], $report->errors());
        self::assertSame([], $report->warnings());

        foreach ($report->files() as $file) {
            if ($file->relativePath === 'TmswpAcfPostFields.php') {
                $this->writeFile($outputDir . '/' . $file->relativePath, $file->contents);
                require $outputDir . '/' . $file->relativePath;
            }
        }

        $class = $namespace . '\\TmswpAcfPostFields';
        self::assertTrue(class_exists($class, false));
        self::assertSame('date_time_raw_format', \constant($class . '::DATE_TIME_RAW_FORMAT'));
        $postId = self::createPost('tmswp_acf_post');
        $fields = self::fieldsOf($class, $postId);
        $instant = new DateTimeImmutable('2024-10-27 00:30:00', new DateTimeZone('UTC'));
        $calls = [
            'setDateTimeRawFormat' => ['Y-m-d'],
            'setLocalTimeInstants' => [$instant],
        ];

        foreach ($calls as $method => $arguments) {
            $call = [$fields, $method];
            self::assertIsCallable($call);
            $call(...$arguments);
        }

        $readText = [$fields, 'dateTimeRawFormat'];
        $readDate = [$fields, 'localTimeInstantsAsDateTime'];
        self::assertIsCallable($readText);
        self::assertIsCallable($readDate);
        self::assertSame('Y-m-d', $readText());
        self::assertSame('2024-10-27 02:30:00', get_post_meta($postId, 'local_time_instants', true));
        $date = $readDate();
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame($instant->getTimestamp(), $date->getTimestamp());
    }

    /**
     * acf/validate_post_idが投稿$postIdだけを$resolvedIdへ解決する間に、$postIdへ$valueをset()する。
     */
    private static function setWhileAcfResolves(AcfMetaStorage $storage, int $postId, int $resolvedId, string $key, string $value): bool
    {
        $resolve = static fn(mixed $acfPostId): mixed => $acfPostId === $postId ? $resolvedId : $acfPostId;
        add_filter('acf/validate_post_id', $resolve);

        try {
            return $storage->set(ObjectType::Post, $postId, $key, $value);
        } finally {
            remove_filter('acf/validate_post_id', $resolve);
        }
    }

    /**
     * 値の形をACFのupdate_valueが変える型(group・relationship・user等)と、条件つきで扱う型の条件に合わないフィールド
     * (multipleのselect、save_other_choiceのradio)のset()・delete()は、設定の誤りとしてAcfBridgeRuntimeExceptionになり、
     * メタを変えない。メッセージは、キー・フィールドの型・扱える型の一覧を含む。読み取りは型を検査しない。
     *
     * @dataProvider unsupportedFields
     */
    public function testUnsupportedFieldTypesAreRejected(string $key, string $fieldType, string $reason): void
    {
        $storage = self::acfMetaStorage();
        $postId = self::createPost('tmswp_acf_post');
        add_post_meta($postId, $key, 'kept');
        $writes = [
            'set' => static fn(): bool => $storage->set(ObjectType::Post, $postId, $key, 'value'),
            'delete' => static fn(): bool => $storage->delete(ObjectType::Post, $postId, $key),
        ];

        foreach ($writes as $name => $write) {
            try {
                $write();
                self::fail("AcfBridgeRuntimeException was not thrown for {$name}");
            } catch (AcfBridgeRuntimeException $exception) {
                self::assertStringContainsString("AcfMetaStorage cannot write meta '{$key}' of the ACF field field_tmswp_acf_post_{$key} (type '{$fieldType}') because {$reason}", $exception->getMessage(), $name);
                self::assertStringContainsString('supports only these field types: ' . implode(', ', AcfMetaStorage::SUPPORTED_FIELD_TYPES), $exception->getMessage(), $name);
            }
        }

        self::assertSame(['kept'], get_post_meta($postId, $key));
        self::assertTrue($storage->has(ObjectType::Post, $postId, $key));
        self::assertSame('kept', $storage->get(ObjectType::Post, $postId, $key));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsupportedFields(): iterable
    {
        yield 'group' => ['slashed_group', 'group', "its type 'group' is not supported"];
        yield 'relationship' => ['related_posts', 'relationship', "its type 'relationship' is not supported"];
        yield 'user' => ['picked_users', 'user', "its type 'user' is not supported"];
        yield 'multipleのselect' => ['tags_select', 'select', 'it is a select field with multiple values'];
        yield 'save_other_choiceのradio' => ['color_radio', 'radio', 'it is a radio field with save_other_choice'];
    }

    /**
     * NULを含む日時のrawは、createFromFormat()のValueErrorにせず、形の不一致として扱う。debugでは例外になり、本番では
     * 記録してnullを返す。
     */
    public function testDateTimeWithNulIsAShapeMismatch(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        update_metadata('post', $postId, 'tmswp_probe_nul', wp_slash("2024-01-01 00:00:00\0"));
        self::assertSame("2024-01-01 00:00:00\0", get_post_meta($postId, 'tmswp_probe_nul', true));

        $logger = new RecordingLogger();
        $production = self::declareProbe($this->productionKernel('Nul', $logger)->config()->appNamespace . '\\Probe')::of($postId);
        self::assertNull($production->dateTime('tmswp_probe_nul', 'Y-m-d H:i:s'));
        self::assertSame([['error', 'tmswp_probe_nul', 'string that contains NUL']], array_map(
            static fn(array $record): array => [$record['level'], $record['context']['field'], $record['context']['actual']],
            $logger->records,
        ));

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("ACF field 'tmswp_probe_nul' of {$postId} has an unexpected value: expected date string in the format 'Y-m-d H:i:s', got string that contains NUL");

        self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of($postId)->dateTime('tmswp_probe_nul', 'Y-m-d H:i:s');
    }

    /**
     * date_time_picker(Y-m-d H:i:s)では、同じ現地の時刻が2回来る間(夏時間の終わり等)の早い方の瞬間は保存されて同じ瞬間と
     * して往復し、遅い方の瞬間はInvalidArgumentExceptionになる。北半球・南半球・重複が30分の地域・深夜0時に重複する地域で、
     * PHPのパーサーがどちらの瞬間を選ぶかに関係なく同じになる。
     *
     * @dataProvider repeatedLocalTimes
     */
    public function testRepeatedLocalTimeStoresOnlyTheEarlierInstant(string $timezone, string $earlierUtc, string $laterUtc, string $localTime): void
    {
        update_option('timezone_string', $timezone);
        $postId = self::createPost('tmswp_acf_post');
        $probe = self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of($postId);
        $utc = new DateTimeZone('UTC');
        $earlier = new DateTimeImmutable($earlierUtc, $utc);
        $later = new DateTimeImmutable($laterUtc, $utc);

        $probe->setDate('tmswp_probe_dst', $earlier, 'Y-m-d H:i:s');
        self::assertSame($localTime, get_post_meta($postId, 'tmswp_probe_dst', true));
        self::assertSame($earlier->getTimestamp(), $probe->dateTime('tmswp_probe_dst', 'Y-m-d H:i:s')?->getTimestamp());

        try {
            $probe->setDate('tmswp_probe_dst', $later, 'Y-m-d H:i:s');
            self::fail('InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("ACF field 'tmswp_probe_dst' cannot store {$later->format('Y-m-d H:i:s')} UTC: the local time {$localTime} in {$timezone} is ambiguous", $exception->getMessage());
        }

        self::assertSame($localTime, get_post_meta($postId, 'tmswp_probe_dst', true));

        // 日付だけ・時刻だけの書式は瞬間を保存しないため、遅い方の瞬間も例外にしない
        foreach (['Ymd' => $later->setTimezone(new DateTimeZone($timezone))->format('Ymd'), 'H:i:s' => substr($localTime, 11)] as $format => $raw) {
            $probe->setDate('tmswp_probe_dst_part', $later, $format);
            self::assertSame($raw, get_post_meta($postId, 'tmswp_probe_dst_part', true), $format);
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function repeatedLocalTimes(): iterable
    {
        yield 'America/New_York' => ['America/New_York', '2024-11-03 05:30:00', '2024-11-03 06:30:00', '2024-11-03 01:30:00'];
        yield 'Europe/Berlin' => ['Europe/Berlin', '2024-10-27 00:30:00', '2024-10-27 01:30:00', '2024-10-27 02:30:00'];
        yield 'Australia/Sydney' => ['Australia/Sydney', '2024-04-06 15:30:00', '2024-04-06 16:30:00', '2024-04-07 02:30:00'];
        yield 'Australia/Lord_Howe(重複が30分)' => ['Australia/Lord_Howe', '2024-04-06 14:45:00', '2024-04-06 15:15:00', '2024-04-07 01:45:00'];
        yield 'America/Havana(深夜0時)' => ['America/Havana', '2024-11-03 04:00:00', '2024-11-03 05:00:00', '2024-11-03 00:00:00'];
    }

    /**
     * 同じ現地の時刻が2回来ない時刻と地域では、どの書式も例外にせず往復する(UTC・夏時間の無い地域・夏時間のある地域の
     * ふつうの時刻・UTC以外のタイムゾーンの入力)。
     */
    public function testUnambiguousLocalTimesRoundTrip(): void
    {
        $postId = self::createPost('tmswp_acf_post');
        $probe = self::declareProbe(self::THEME_NAMESPACE . '\\Probe')::of($postId);
        $cases = [
            ['UTC', new DateTimeImmutable('2024-11-03 05:30:00', new DateTimeZone('UTC')), '2024-11-03 05:30:00'],
            ['Asia/Tokyo', new DateTimeImmutable('2024-11-03 05:30:00', new DateTimeZone('UTC')), '2024-11-03 14:30:00'],
            ['America/New_York', new DateTimeImmutable('2024-12-01 17:00:00', new DateTimeZone('UTC')), '2024-12-01 12:00:00'],
            ['America/New_York', new DateTimeImmutable('2024-07-01 18:00:00', new DateTimeZone('Europe/Berlin')), '2024-07-01 12:00:00'],
        ];

        foreach ($cases as [$timezone, $instant, $raw]) {
            update_option('timezone_string', $timezone);
            $probe->setDate('tmswp_probe_dst', $instant, 'Y-m-d H:i:s');
            self::assertSame($raw, get_post_meta($postId, 'tmswp_probe_dst', true), $timezone);
            self::assertSame($instant->getTimestamp(), $probe->dateTime('tmswp_probe_dst', 'Y-m-d H:i:s')?->getTimestamp(), $timezone);
            $probe->setDate('tmswp_probe_dst_part', $instant, 'Ymd');
            $probe->setDate('tmswp_probe_dst_part', $instant, 'H:i:s');
        }
    }

    /**
     * acf_get_attachment()と同じ形の画像の配列。
     *
     * @return array{ID: int, id: int, title: string, filename: string, filesize: int, url: string, link: string, alt: string, author: string, description: string, caption: string, name: string, status: string, uploaded_to: int, date: string, modified: string, menu_order: int, mime_type: string, type: string, subtype: string, icon: string, width: int, height: int, sizes: array<string, int|string>}
     */
    private static function image(int $id): array
    {
        return [
            'ID' => $id,
            'id' => $id,
            'title' => "Image {$id}",
            'filename' => "image-{$id}.jpg",
            'filesize' => 1024,
            'url' => "https://example.com/image-{$id}.jpg",
            'link' => "https://example.com/?attachment_id={$id}",
            'alt' => '',
            'author' => '1',
            'description' => '',
            'caption' => '',
            'name' => "image-{$id}",
            'status' => 'inherit',
            'uploaded_to' => 0,
            'date' => '2024-01-01 00:00:00',
            'modified' => '2024-01-01 00:00:00',
            'menu_order' => 0,
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'subtype' => 'jpeg',
            'icon' => 'https://example.com/wp-includes/images/media/default.svg',
            'width' => 800,
            'height' => 600,
            'sizes' => ['thumbnail' => "https://example.com/image-{$id}-150x150.jpg", 'thumbnail-width' => 150, 'thumbnail-height' => 150],
        ];
    }

    /**
     * AcfFieldAccessを使うプローブのクラスを$namespaceに宣言する。クラス名は呼び出しごとに変える(同じプロセスで
     * 同じクラスを二度宣言できないため)。
     *
     * @return class-string<AcfAccessProbe>
     */
    private static function declareProbe(string $namespace): string
    {
        $shortName = 'AcfAccessProbe' . bin2hex(random_bytes(4));
        $file = sys_get_temp_dir() . '/' . $shortName . '.php';
        file_put_contents($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use DateTimeImmutable;
use DateTimeInterface;
use TMSWP\\AcfBridge\\AcfFieldAccess;
use TMSWP\\AcfBridge\\Tests\\Integration\\AcfAccessProbe;

final class {$shortName} implements AcfAccessProbe
{
    use AcfFieldAccess;

    public static function of(int|string \$acfId): static
    {
        return new self(\$acfId);
    }

    public function string(string \$name): string { return \$this->acfString(self::key(\$name)); }
    public function nullableString(string \$name): ?string { return \$this->acfNullableString(self::key(\$name)); }
    public function number(string \$name): int|float|null { return \$this->acfNumber(self::key(\$name)); }
    public function int(string \$name): ?int { return \$this->acfInt(self::key(\$name)); }
    public function bool(string \$name): bool { return \$this->acfBool(self::key(\$name)); }
    public function rows(string \$name): array { return \$this->acfRows(self::key(\$name), static fn(array \$row, string \$path): array => \$row); }
    public function intList(string \$name): array { return \$this->acfIntList(self::key(\$name)); }
    public function group(string \$name): ?array { return \$this->acfGroup(self::key(\$name), static fn(array \$group, string \$path): array => \$group); }
    public function image(string \$name): ?array { return \$this->acfImage(self::key(\$name)); }
    public function imageList(string \$name): array { return \$this->acfImageList(self::key(\$name)); }
    public function dateTime(string \$name, string \$rawFormat): ?DateTimeImmutable { return \$this->acfDateTime(self::key(\$name), \$rawFormat); }
    public function setDate(string \$name, DateTimeInterface|string|null \$value, string \$rawFormat): void { \$this->acfUpdateDate(self::key(\$name), \$value, \$rawFormat); }

    private static function key(string \$name): string { return 'field_' . \$name; }
}
PHP);

        try {
            require $file;
        } finally {
            unlink($file);
        }

        return self::fixtureClass($namespace . '\\' . $shortName, AcfAccessProbe::class);
    }

    /**
     * fixtureのテーマのacf-jsonから、$namespaceへ生成クラスを生成し、TmswpAcfPostFieldsを読み込んでそのクラス名を返す。
     */
    private function generateThemeFieldsInto(string $namespace): string
    {
        $outputDir = $this->createTempDirectory('acf-generated');
        $json = (new AcfJsonRepository([get_stylesheet_directory() . '/acf-json']))->load();
        $report = (new Generator())->generate($json->fieldGroups, new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $outputDir, namespace: $namespace),
            optionsPagePostIds: $json->optionsPagePostIds,
            sourceHash: $json->sourceHash,
        ));
        self::assertSame([], $report->errors());

        foreach ($report->files() as $file) {
            if ($file->relativePath === 'TmswpAcfPostFields.php') {
                $this->writeFile($outputDir . '/' . $file->relativePath, $file->contents);
                require $outputDir . '/' . $file->relativePath;
            }
        }

        $class = $namespace . '\\TmswpAcfPostFields';
        self::assertTrue(class_exists($class, false));

        return $class;
    }

    /**
     * ACFのローカルのフィールドグループ(tmswp_acf_postの投稿)として$fieldsを登録する。データベースには書かない。登録は
     * プロセスの中で続くため、登録済みのグループは登録し直さない(acf_add_local_field_group()は登録済みのkeyでfalseを返す)。
     *
     * @param mixed $fields acf-jsonのfieldsの形の配列
     */
    private static function registerLocalFieldGroup(string $key, mixed $fields): void
    {
        if (acf_is_local_field_group($key)) {
            return;
        }

        self::assertTrue(acf_add_local_field_group([
            'key' => $key,
            'title' => $key,
            'fields' => $fields,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'tmswp_acf_post']]],
        ]));
    }

    /**
     * $groupから$namespaceへ生成したアクセサのクラス(AcfManifestでないもの。$groupは1つのターゲットに解決する)を読み込み、
     * そのクラス名を返す。
     */
    private function generateFieldsInto(string $namespace, FieldGroup $group): string
    {
        $outputDir = $this->createTempDirectory('acf-generated-group');
        $report = (new Generator())->generate([$group], new GeneratorContext(
            config: new BridgeConfig(jsonDirs: [], outputDir: $outputDir, namespace: $namespace),
            optionsPagePostIds: [],
            sourceHash: $group->key,
        ));
        self::assertSame([], $report->errors());
        $files = array_values(array_filter($report->files(), static fn(GeneratedFile $file): bool => $file->relativePath !== 'AcfManifest.php'));
        self::assertCount(1, $files);
        $file = $files[0];
        $this->writeFile($outputDir . '/' . $file->relativePath, $file->contents);
        require $outputDir . '/' . $file->relativePath;
        $class = $namespace . '\\' . basename($file->relativePath, '.php');
        self::assertTrue(class_exists($class, false));

        return $class;
    }

    /**
     * $objectの$methodを呼ぶ(生成クラスはPHPStanの解析対象外のため、呼び出せることを確かめてから呼ぶ)。
     */
    private static function callOn(object $object, string $method, mixed ...$arguments): mixed
    {
        $call = [$object, $method];
        self::assertIsCallable($call);

        return $call(...$arguments);
    }

    /** 生成クラス$classのof($postId)を呼び、そのインスタンスを返す(生成クラスはPHPStanの解析対象外)。 */
    private static function fieldsOf(string $class, int $postId): object
    {
        $of = [$class, 'of'];
        self::assertIsCallable($of);
        $fields = $of($postId);
        self::assertIsObject($fields);

        return $fields;
    }

    /** ファイルの無い添付ファイル(MIMEタイプ$mimeType、親$parentId)を作り、そのIDを返す。 */
    private static function createAttachment(string $mimeType, int $parentId): int
    {
        $attachmentId = wp_insert_attachment(['post_mime_type' => $mimeType, 'post_title' => 'ACF bridge attachment', 'post_status' => 'inherit'], false, $parentId);
        self::assertIsInt($attachmentId);
        self::assertGreaterThan(0, $attachmentId);

        return $attachmentId;
    }

    private static function acfMetaStorage(): AcfMetaStorage
    {
        $storage = self::themeKernel()->container()->get(AcfMetaStorage::class);
        self::assertInstanceOf(AcfMetaStorage::class, $storage);

        return $storage;
    }

    /**
     * $needleを含むSQLのうち$verbsの文を、存在しないテーブルへのSQLに書き換え、データベースのエラーにした状態で
     * $callbackを実行する。
     *
     * @param callable(): void $callback
     */
    private static function withBrokenQueries(string $needle, string $verbs, callable $callback): void
    {
        $wpdb = self::wpdb();
        $broken = 'UPDATE ' . $wpdb->prefix . 'tmswp_missing_table SET meta_value = 1';
        $breakQueries = static fn(string $query): string => str_contains($query, $needle) && preg_match('/^\s*(' . $verbs . ')\b/i', $query) === 1 ? $broken : $query;
        $suppressed = $wpdb->suppress_errors(true);
        add_filter('query', $breakQueries);

        try {
            $callback();
        } finally {
            remove_filter('query', $breakQueries);
            $wpdb->suppress_errors($suppressed);
        }
    }

    /**
     * 本番相当(debug=false)のプラグイン形態の、コンポーネントの無いKernelをinitより前にbootする。ログは$loggerに記録する。
     */
    private function productionKernel(string $name, RecordingLogger $logger): Kernel
    {
        $root = $this->createTempDirectory('acf-integration-' . strtolower($name));
        self::assertTrue(mkdir($root . '/app'));
        $this->writeFile($root . '/plugin.php', "<?php\n");
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            'TmswpAcfIntegration' . $name . uniqid(),
            false,
            logChannels: static fn(): array => ['integration' => new LogChannel($logger)],
        );
        (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        $this->bootBeforeInit($config);

        return Kernel::get($config->appNamespace);
    }
}

/**
 * AcfFieldAccessのヘルパを公開するプローブ。AcfBridgeIntegrationTest::declareProbe()が、Kernelの名前空間に実装を宣言する。
 *
 * @phpstan-import-type AcfImage from \TMSWP\AcfBridge\AcfTypes
 */
interface AcfAccessProbe
{
    public static function of(int|string $acfId): static;

    public function has(string $name): bool;

    public function value(string $name): mixed;

    public function update(string $name, mixed $value): bool;

    public function delete(string $name): bool;

    public function string(string $name): string;

    public function nullableString(string $name): ?string;

    public function number(string $name): int|float|null;

    public function int(string $name): ?int;

    public function bool(string $name): bool;

    /** @return list<array<string, mixed>> */
    public function rows(string $name): array;

    /** @return list<int> */
    public function intList(string $name): array;

    /** @return array<string, mixed>|null */
    public function group(string $name): ?array;

    /** @return AcfImage|null */
    public function image(string $name): ?array;

    /** @return list<AcfImage> */
    public function imageList(string $name): array;

    public function dateTime(string $name, string $rawFormat): ?DateTimeImmutable;

    public function setDate(string $name, DateTimeInterface|string|null $value, string $rawFormat): void;
}
