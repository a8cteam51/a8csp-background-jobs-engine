<?php

namespace Tests\EndToEnd;

use Tests\Support\EndToEndTester;

class ActivationCest
{
	public function test_it_deactivates_activates_correctly(EndToEndTester $I): void
	{
		$I->loginAsAdmin();
		$I->amOnPluginsPage();

		$I->seePluginDeactivated('a8csp-background-tasks');

		$I->activatePlugin('a8csp-background-tasks');
		$I->seePluginActivated('a8csp-background-tasks');

		$I->deactivatePlugin('a8csp-background-tasks');
		$I->seePluginDeactivated('a8csp-background-tasks');
	}
}
