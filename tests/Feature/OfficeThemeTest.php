<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OfficeThemeTest extends TestCase
{
    public function test_office_assets_publish_persistent_operational_themes(): void
    {
        $css = file_get_contents(resource_path('css/office-corporate.css'));
        $javascript = file_get_contents(resource_path('js/office-navigation.js'));

        $this->assertIsString($css);
        $this->assertIsString($javascript);

        $this->assertStringContainsString(
            ':root[data-office-theme="dark-industrial"]',
            $css,
        );
        $this->assertStringContainsString(
            ':root[data-office-theme="light-professional"]',
            $css,
        );
        $this->assertStringContainsString('OFFICE_THEME_KEY', $javascript);
        $this->assertStringContainsString('applyOfficeTheme', $javascript);
        $this->assertStringContainsString('nextOfficeTheme', $javascript);
        $this->assertStringContainsString(':root[data-office-theme="dark-rose"]', $css);
        $this->assertStringContainsString('localStorage.setItem(officeThemeStorageKey(identity), officeTheme)', $javascript);
        $this->assertStringContainsString('get themes() { return officeThemes(themeIdentity()); }', $javascript);
        $this->assertStringNotContainsString('officeThemeSelector', $javascript);
    }

    public function test_every_office_with_shared_navigation_is_inside_the_visual_scope(): void
    {
        foreach (File::allFiles(resource_path('views/office')) as $file) {
            $view = $file->getContents();

            if (! str_contains($view, '<x-office.navigation')) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/<main\b[^>]*class="[^"]*\boffice-app\b[^"]*"[^>]*>/',
                $view,
                $file->getRelativePathname(),
            );
        }
    }
}
