<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Config;

class ImageProcessingTest extends ConfigTestCase
{
    public function testSetImageQualityWithSingleArgumentAppliesToAllFormats(): void
    {
        $config = new Config();
        $result = $config->setImageQuality(78);

        self::assertSame($config, $result, 'setImageQuality must return $this for fluent chaining');
        self::assertSame(78, $GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality']);
        self::assertSame(78, $GLOBALS['TYPO3_CONF_VARS']['GFX']['webp_quality']);
        self::assertSame(78, $GLOBALS['TYPO3_CONF_VARS']['GFX']['avif_quality']);
        self::assertSame(78, $GLOBALS['TYPO3_CONF_VARS']['GFX']['heif_quality']);
    }

    public function testSetImageQualityWithFormatSpecificValues(): void
    {
        (new Config())->setImageQuality(jpeg: 85, webp: 80, avif: 75, heif: 90);

        self::assertSame(85, $GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality']);
        self::assertSame(80, $GLOBALS['TYPO3_CONF_VARS']['GFX']['webp_quality']);
        self::assertSame(75, $GLOBALS['TYPO3_CONF_VARS']['GFX']['avif_quality']);
        self::assertSame(90, $GLOBALS['TYPO3_CONF_VARS']['GFX']['heif_quality']);
    }

    public function testSetImageQualityRejectsOutOfRangeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/jpeg must be 1\.\.100/');

        (new Config())->setImageQuality(0);
    }

    public function testSetImageQualityRejectsOverHundred(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/webp must be 1\.\.100/');

        (new Config())->setImageQuality(jpeg: 78, webp: 101);
    }

    public function testSetImageColorspaceSetsSrgb(): void
    {
        $config = new Config();
        $result = $config->setImageColorspace('sRGB');

        self::assertSame($config, $result, 'setImageColorspace must return $this for fluent chaining');
        self::assertSame('sRGB', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_colorspace']);
    }

    public function testSetImageColorspaceAcceptsAllValidValues(): void
    {
        foreach (['sRGB', 'RGB', 'Gray', 'CMYK'] as $colorspace) {
            (new Config())->setImageColorspace($colorspace);
            self::assertSame($colorspace, $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_colorspace']);
        }
    }

    public function testSetImageColorspaceRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid colorspace "HSL"/');

        (new Config())->setImageColorspace('HSL');
    }

    public function testAllowImageFileExtensionsAppendsToExistingList(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'gif,jpg,png';

        $config = new Config();
        $result = $config->allowImageFileExtensions('heic', 'heif', 'avif');

        self::assertSame($config, $result, 'allowImageFileExtensions must return $this for fluent chaining');
        self::assertSame('gif,jpg,png,heic,heif,avif', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);
    }

    public function testAllowImageFileExtensionsDeduplicates(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'gif,jpg,heic';

        (new Config())->allowImageFileExtensions('heic', 'heif', 'jpg');

        self::assertSame('gif,jpg,heic,heif', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);
    }

    public function testAllowImageFileExtensionsNormalizesInput(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'] = 'jpg';

        // Mixed-case + leading dot + whitespace should all normalize to lowercase, no-dot
        (new Config())->allowImageFileExtensions('HEIC', '.heif', '  AVIF  ');

        self::assertSame('jpg,heic,heif,avif', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);
    }

    public function testAllowImageFileExtensionsHandlesEmptyInitialList(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);

        (new Config())->allowImageFileExtensions('jpg', 'webp');

        self::assertSame('jpg,webp', $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext']);
    }

    public function testUseImageMagickSwitchesProcessorAndPaths(): void
    {
        // processor_path_lzw wird gern vergessen; TYPO3 ruft darueber die
        // Kompression auf, und ein leerer Wert laesst die Bildbearbeitung
        // stillschweigend im Original stehen.
        $config = new Config();
        $result = $config->useImageMagick('/opt/homebrew/bin/');

        self::assertSame($config, $result, 'useImageMagick must return $this for fluent chaining');
        self::assertSame('ImageMagick', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor']);
        self::assertSame('/opt/homebrew/bin/', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path']);
        self::assertSame('/opt/homebrew/bin/', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw']);
    }

    public function testUseImageMagickDefaultsToUsrBin(): void
    {
        (new Config())->useImageMagick();

        self::assertSame('/usr/bin/', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path']);
    }

    public function testUseGraphicsMagickOverridesAPreviousImageMagick(): void
    {
        // applyDefaults() setzt GraphicsMagick. Wer danach ImageMagick waehlt
        // und spaeter zurueckwechselt, muss wieder vollstaendig dort landen -
        // ein halb umgestellter processor ist der Fall, den man im Betrieb
        // nicht sieht.
        $config = new Config();
        $config->useImageMagick('/opt/im/');
        $config->useGraphicsMagick('/opt/gm/');

        self::assertSame('GraphicsMagick', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor']);
        self::assertSame('/opt/gm/', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path']);
        self::assertSame('/opt/gm/', $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path_lzw']);
    }
}
