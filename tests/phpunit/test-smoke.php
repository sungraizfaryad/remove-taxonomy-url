<?php
class Smoke_Test extends WP_UnitTestCase {
    public function test_plugin_loaded() {
        $this->assertTrue( defined( 'REMOVE_TAXONOMY_URL_VERSION' ) );
    }
}
