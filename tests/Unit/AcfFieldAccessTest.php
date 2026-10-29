<?php

declare(strict_types=1);

namespace TMSWP\AcfBridge\Tests\Unit;

use App\Acf\ProductFields;
use App\FieldAccess\AcfFieldAccessFixture;
use ArrayObject;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use TMSWP\AcfBridge\Exception\AcfBridgeRuntimeException;
use TMSWP\AcfBridge\Exception\AcfNotActiveException;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\DiscoveryResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UnitTestCase;

/**
 * ACFを読み込まないユニットテストの環境で、AcfFieldAccessのACFに触れない部分を確かめる。
 *
 * 値を受け取るヘルパ(`*Of()`)は、ジェネレータがgroup・repeater・flexible_contentの入れ子に生成する正規化の呼び出しと
 * 同じ形(AcfFieldAccessFixtureのgroup()等)で確かめる。repeater・flexible_content・galleryはACF Proの型で、CIの無料版
 * ACFでは値を保存できないためである。形の不一致の扱いを決めるKernelは、fixtureのクラスの名前空間(App\FieldAccess)で
 * 起動する。
 * field keyによるACFの呼び出し・has()・日時の往復は、実物の無料版ACFの統合テスト(AcfBridgeIntegrationTest)で確かめる。
 */
final class AcfFieldAccessTest extends UnitTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once __DIR__ . '/../fixtures/field-access/AcfFieldAccessFixture.php';
    }

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

    public function testItKeepsAcfIdWithoutAccessingAcfRuntime(): void
    {
        $access = AcfFieldAccessFixture::make('term_10');

        self::assertSame('term_10', $access->acfId());
    }

    /**
     * ACFに触れる操作は、ACFが有効でなければ(get_fieldが無ければ)AcfNotActiveExceptionになる(docs/09 §10)。
     *
     * @param Closure(AcfFieldAccessFixture): mixed $call
     */
    #[DataProvider('acfCalls')]
    public function testAcfAccessRequiresActiveAcf(string $method, Closure $call): void
    {
        self::assertFalse(\function_exists('get_field'));

        $this->expectException(AcfNotActiveException::class);
        $this->expectExceptionMessage(AcfFieldAccessFixture::class . "::{$method} requires Advanced Custom Fields (ACF) 6.2+");

        $call(AcfFieldAccessFixture::make(10));
    }

    /**
     * ACFが有効でないときの例外のメッセージは、docs/09 §10の正準形の全文(`{callable}`は`{呼び出したクラス}::{メソッド}`)
     * と一致する(受け入れ基準「ACF不在環境で生成クラス…へのアクセスがAcfNotActiveException(正準メッセージ)になる」)。
     *
     * @param Closure(AcfFieldAccessFixture): mixed $call
     */
    #[DataProvider('acfCalls')]
    public function testAcfNotActiveMessageIsTheCanonicalFullText(string $method, Closure $call): void
    {
        try {
            $call(AcfFieldAccessFixture::make(10));
            self::fail('AcfNotActiveException was not thrown');
        } catch (AcfNotActiveException $exception) {
            self::assertSame(self::canonicalAcfNotActiveMessage(AcfFieldAccessFixture::class . "::{$method}"), $exception->getMessage());
        }
    }

    /**
     * 生成したクラス(fixtures/expected-appのProductFields)のアクセサも、ACFが有効でなければ`of()`では投げず、値への
     * アクセスで正準形のメッセージのAcfNotActiveExceptionを投げる(docs/09 §10)。`{callable}`は生成クラスと、アクセサが
     * 呼ぶtraitのメソッドの名前になる。
     */
    public function testGeneratedClassAccessorThrowsTheCanonicalAcfNotActiveMessage(): void
    {
        require_once __DIR__ . '/../fixtures/expected-app/Acf/ProductFields.php';
        $product = ProductFields::of(10);

        try {
            $product->price();
            self::fail('AcfNotActiveException was not thrown');
        } catch (AcfNotActiveException $exception) {
            self::assertSame(self::canonicalAcfNotActiveMessage(ProductFields::class . '::acfNumber'), $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, Closure(AcfFieldAccessFixture): mixed}> */
    public static function acfCalls(): iterable
    {
        yield 'has' => ['has', static fn(AcfFieldAccessFixture $access): bool => $access->has('price')];
        yield 'value' => ['value', static fn(AcfFieldAccessFixture $access): mixed => $access->value('price')];
        yield 'rawValue' => ['rawValue', static fn(AcfFieldAccessFixture $access): mixed => $access->rawValue('price')];
        yield 'update' => ['update', static fn(AcfFieldAccessFixture $access): bool => $access->update('price', '1200')];
        yield 'delete' => ['delete', static fn(AcfFieldAccessFixture $access): bool => $access->delete('price')];
        yield 'acfString' => ['acfString', static fn(AcfFieldAccessFixture $access): string => $access->stringByKey('field_price')];
        yield 'acfRows' => ['acfRows', static fn(AcfFieldAccessFixture $access): array => $access->rowsByKey('field_rows')];
        yield 'acfUpdate' => ['acfUpdate', static fn(AcfFieldAccessFixture $access): null => $access->updateByKey('field_price', '1200')];
        yield 'acfUpdateDate' => ['acfUpdateDate', static fn(AcfFieldAccessFixture $access): null => $access->setDate('field_published_at', '20260613')];
        yield 'acfHas' => ['acfHas', static fn(AcfFieldAccessFixture $access): bool => $access->hasByKey('field_price')];
        yield 'acfDelete' => ['acfDelete', static fn(AcfFieldAccessFixture $access): bool => $access->deleteByKey('field_price')];
    }

    /**
     * 入れ子の未設定の値(null・false・''、groupの空の配列)は、サブフィールドの型ごとの未設定の値に正規化し、例外にも
     * ログにもしない。
     */
    public function testNestedUnsetValuesAreNormalized(): void
    {
        $this->bootKernel(true);
        $access = AcfFieldAccessFixture::make(10);

        self::assertNull($access->group(null));
        self::assertNull($access->group(false));
        self::assertNull($access->group([]));
        self::assertSame(['title' => '', 'photo' => null, 'enabled' => false, 'tags' => [], 'image' => null, 'items' => []], $access->group(['title' => null, 'photo' => false, 'enabled' => '', 'tags' => '', 'image' => false, 'items' => false]));
        self::assertSame(['title' => '', 'photo' => null, 'enabled' => false, 'tags' => [], 'image' => null, 'items' => []], $access->group(['other' => 'ignored']));
        self::assertSame([], $access->rows(null));
        self::assertSame([], $access->rows(''));
        self::assertSame([], $access->layouts(false));
        self::assertSame([], $access->emptyLayouts(null));
        self::assertSame([], $access->emptyLayouts(''));
    }

    /**
     * color_picker(return_formatがarray)は、ACFが値の無いときと色として読めない文字列のときに返すrgba(0,0,0,0)の配列を
     * 未設定としてnullにする。ほかの色は、どれか1つの成分(alphaを含む)が0でなければそのまま返す。
     */
    public function testTransparentBlackColorIsUnset(): void
    {
        $this->bootKernel(true);
        $access = AcfFieldAccessFixture::make(10);

        self::assertNull($access->color(['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0.0]));
        self::assertNull($access->color(null));
        self::assertNull($access->color(''));

        foreach ([
            ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 0.5],
            ['red' => 0, 'green' => 0, 'blue' => 0, 'alpha' => 1.0],
            ['red' => 0, 'green' => 0, 'blue' => 1, 'alpha' => 0.0],
            ['red' => 255, 'green' => 0, 'blue' => 0, 'alpha' => 0.0],
        ] as $color) {
            self::assertSame($color, $access->color($color));
        }
    }

    /** 入れ子の値を、全階層でサブフィールドの型どおりに正規化する(数値の文字列は数値、添字の飛んだlistは詰め直す)。 */
    public function testNestedValuesAreNormalizedAtEveryLevel(): void
    {
        $this->bootKernel(true);
        $access = AcfFieldAccessFixture::make(10);
        $object = new ArrayObject();

        self::assertSame(
            ['title' => '12', 'photo' => 34, 'enabled' => true, 'tags' => ['red', '5'], 'image' => self::image(), 'items' => [['label' => 'A', 'amount' => 1.5, 'owner' => $object]]],
            $access->group(['title' => 12, 'photo' => '34', 'enabled' => 1, 'tags' => [2 => 'red', 3 => 5], 'image' => self::image(), 'items' => [3 => ['label' => 'A', 'amount' => '1.5', 'owner' => $object]]]),
        );
        self::assertSame(
            [['label' => 'A', 'amount' => 3, 'owner' => null], ['label' => '', 'amount' => null, 'owner' => $object]],
            $access->rows([['label' => 'A', 'amount' => '3'], ['owner' => $object]]),
        );
        self::assertSame(
            [
                ['acf_fc_layout' => 'hero', 'title' => 'Top', 'buttons' => [['url' => 'https://example.com/']]],
                ['acf_fc_layout' => 'text_block', 'body' => '<p>body</p>'],
            ],
            $access->layouts([
                ['acf_fc_layout' => 'hero', 'title' => 'Top', 'buttons' => [['url' => 'https://example.com/']], 'extra' => 'ignored'],
                ['acf_fc_layout' => 'text_block', 'body' => '<p>body</p>'],
            ]),
        );
    }

    /**
     * debugのKernelでは、入れ子の形の不一致は、どの階層のどのフィールドか(`group_value.items[0].amount`の形)と型を示す
     * AcfBridgeRuntimeExceptionになる。
     *
     * @param Closure(AcfFieldAccessFixture): mixed $read
     */
    #[DataProvider('nestedMismatches')]
    public function testNestedMismatchThrowsWithThePathInDebug(Closure $read, string $path, string $types): void
    {
        $this->bootKernel(true);

        $this->expectException(AcfBridgeRuntimeException::class);
        $this->expectExceptionMessage("ACF field '{$path}' of 10 has an unexpected value: {$types} (" . AcfFieldAccessFixture::class . ')');

        $read(AcfFieldAccessFixture::make(10));
    }

    /** @return iterable<string, array{Closure(AcfFieldAccessFixture): mixed, string, string}> */
    public static function nestedMismatches(): iterable
    {
        yield 'group is a list' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['a', 'b']), 'group_value', 'expected array with string keys, got list'];
        yield 'group is a string' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group('text'), 'group_value', 'expected array with string keys, got string'];
        yield 'sub field string' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['title' => ['x']]), 'group_value.title', 'expected string, got array'];
        yield 'sub field int' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['photo' => '1.5']), 'group_value.photo', 'expected int, got float'];
        yield 'sub field bool' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['enabled' => []]), 'group_value.enabled', 'expected bool, got array'];
        yield 'sub field list element' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['tags' => ['red', []]]), 'group_value.tags[1]', 'expected string, got array of another shape'];
        yield 'sub field image' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['image' => ['ID' => 'x']]), 'group_value.image', 'expected AcfImage, got array of another shape'];
        yield 'repeater in group' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['items' => ['a' => []]]), 'group_value.items', 'expected list, got array with string keys'];
        yield 'row in group' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['items' => [['label' => 'A'], 7]]), 'group_value.items[1]', 'expected row (array with string keys), got int'];
        yield 'sub field of row in group' => [static fn(AcfFieldAccessFixture $access): ?array => $access->group(['items' => [['amount' => 'abc']]]), 'group_value.items[0].amount', 'expected number, got string'];
        yield 'object sub field' => [static fn(AcfFieldAccessFixture $access): array => $access->rows([['owner' => new stdClass()]]), 'rows_value[0].owner', 'expected ArrayObject, got stdClass'];
        yield 'row is a list' => [static fn(AcfFieldAccessFixture $access): array => $access->rows([['A']]), 'rows_value[0]', 'expected row (array with string keys), got list'];
        yield 'unknown layout' => [static fn(AcfFieldAccessFixture $access): array => $access->layouts([['acf_fc_layout' => 'gallery']]), 'sections[0].acf_fc_layout', "expected layout name ('hero', 'text_block'), got string that is not a layout of the field"];
        yield 'missing layout' => [static fn(AcfFieldAccessFixture $access): array => $access->layouts([['title' => 'Top']]), 'sections[0].acf_fc_layout', "expected layout name ('hero', 'text_block'), got null"];
        yield 'sub field of a layout' => [static fn(AcfFieldAccessFixture $access): array => $access->layouts([['acf_fc_layout' => 'text_block', 'body' => 1], ['acf_fc_layout' => 'hero', 'title' => new stdClass()]]), 'sections[1].title', 'expected string, got stdClass'];
        yield 'row of a flexible content without layouts' => [static fn(AcfFieldAccessFixture $access): array => $access->emptyLayouts([['acf_fc_layout' => 'hero']]), 'empty_sections[0]', 'expected no row (the flexible content field has no layouts), got array'];
        yield 'flexible content without layouts is not a list' => [static fn(AcfFieldAccessFixture $access): array => $access->emptyLayouts('text'), 'empty_sections', 'expected list, got string'];
        yield 'repeater in a layout' => [static fn(AcfFieldAccessFixture $access): array => $access->layouts([['acf_fc_layout' => 'hero', 'buttons' => [['url' => []]]]]), 'sections[0].buttons[0].url', 'expected string, got array'];
    }

    /**
     * 本番のKernelでは、入れ子の形の不一致をそのKernelのロガーにerrorで記録し、サブフィールドは未設定の値にし、不正な行と
     * レイアウトの行だけを除いてほかの行を返す。記録は位置・post_id・型と生成クラスだけを含み、値そのものは含まない。
     */
    public function testNestedMismatchIsLoggedAndNormalizedInProduction(): void
    {
        $logger = $this->bootKernel(false);
        $access = AcfFieldAccessFixture::make(10);

        self::assertSame(
            [['label' => 'secret', 'amount' => null, 'owner' => null], ['label' => 'B', 'amount' => 2, 'owner' => null]],
            $access->rows([['label' => 'secret', 'amount' => 'hidden'], 'hidden row', ['label' => 'B', 'amount' => 2]]),
        );
        self::assertSame(
            [['acf_fc_layout' => 'text_block', 'body' => '']],
            $access->layouts([['acf_fc_layout' => 'hidden layout'], ['acf_fc_layout' => 'text_block', 'body' => ['hidden']]]),
        );

        self::assertSame([], $access->emptyLayouts([['acf_fc_layout' => 'hidden'], 'hidden row']));

        self::assertSame([
            ['error', 'rows_value[0].amount', 'number', 'string'],
            ['error', 'rows_value[1]', 'row (array with string keys)', 'string'],
            ['error', 'sections[0].acf_fc_layout', "layout name ('hero', 'text_block')", 'string that is not a layout of the field'],
            ['error', 'sections[1].body', 'string', 'array'],
            ['error', 'empty_sections[0]', 'no row (the flexible content field has no layouts)', 'array'],
            ['error', 'empty_sections[1]', 'no row (the flexible content field has no layouts)', 'string'],
        ], array_map(static fn(array $record): array => [$record['level'], $record['context']['field'] ?? null, $record['context']['expected'] ?? null, $record['context']['actual'] ?? null], $logger->records));

        foreach ($logger->records as $record) {
            self::assertSame('tmswp.acf: ACF field {field} of {acfId} has an unexpected value: expected {expected}, got {actual} ({class})', $record['message']);
            self::assertSame(['field', 'acfId', 'expected', 'actual', 'class'], array_keys($record['context']));
            self::assertSame('10', $record['context']['acfId']);
            self::assertSame(AcfFieldAccessFixture::class, $record['context']['class']);
            self::assertStringNotContainsString('hidden', (string) json_encode($record['context']));
        }
    }

    /**
     * fixtureのクラスの名前空間のKernelを起動する(形の不一致の扱いはKernel::forClass()で決まる)。ログは返すロガーに記録する。
     */
    private function bootKernel(bool $debug): RecordingLogger
    {
        $logger = new RecordingLogger();
        $appDir = $this->createTempDirectory('acf-field-access') . '/app';
        self::assertTrue(mkdir($appDir));
        $config = KernelConfig::theme($appDir, 'App\\FieldAccess', $debug, logChannels: static fn(): array => ['test' => new LogChannel($logger)]);

        // debugでないKernelは、探索の結果をファイルから読む
        if (!$debug) {
            (new DiscoveryFile($config))->write(new DiscoveryResult([], []));
        }

        Kernel::boot($config);

        return $logger;
    }

    /** docs/09 §10のAcfNotActiveExceptionのメッセージの正準形 */
    private static function canonicalAcfNotActiveMessage(string $callable): string
    {
        return "{$callable} requires Advanced Custom Fields (ACF) 6.2+ to be installed and active. Install/activate the ACF plugin, or remove tmswp/acf-bridge usages.";
    }

    /** @return array<string, mixed> */
    private static function image(): array
    {
        return [
            'ID' => 3,
            'id' => 3,
            'title' => 'Image',
            'filename' => 'image.jpg',
            'filesize' => 1024,
            'url' => 'https://example.com/image.jpg',
            'link' => 'https://example.com/?attachment_id=3',
            'alt' => '',
            'author' => '1',
            'description' => '',
            'caption' => '',
            'name' => 'image',
            'status' => 'inherit',
            'uploaded_to' => 0,
            'date' => '2024-01-01 00:00:00',
            'modified' => '2024-01-01 00:00:00',
            'menu_order' => 0,
            'mime_type' => 'image/jpeg',
            'type' => 'image',
            'subtype' => 'jpeg',
            'icon' => false,
            'width' => 800,
            'height' => 600,
            'sizes' => ['thumbnail' => 'https://example.com/image-150x150.jpg', 'thumbnail-width' => 150],
        ];
    }
}
