<?php

class QuickstartImageButtonTestCase extends PradoGenericSelenium2Test
{
	function test ()
	{
		$this->url("../../demos/quickstart/index.php?page=Controls.Samples.TImageButton.Home&amp;notheme=true&amp;lang=en");

		$this->assertEquals("PRADO QuickStart Sample", $this->title());

		// a click button
		$this->byXPath("//input[@type='image' and @alt='hello world']")->click();
		$this->assertContains("You clicked at ", $this->source());

		// a command button
		$this->byName("ctl0\$body\$ctl1")->click();
		$this->assertContains("Command name: test, Command parameter: value", $this->source());

		// a button causing validation
		$this->assertNotVisible('ctl0_body_ctl2');
		$this->byId("ctl0_body_ctl3")->click();
//		$this->pause(1000);
		$this->assertVisible('ctl0_body_ctl2');
		$this->type("ctl0\$body\$TextBox", "test");
		$this->byId("ctl0_body_ctl3")->click();
		$this->assertNotVisible('ctl0_body_ctl2');
	}
}
