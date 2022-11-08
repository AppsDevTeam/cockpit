<?php

namespace ADT\Cockpit;

interface CockpitTranslator
{
	public function getDefaultLocale(): string;
	public function getLocale(): string;
}