<?php

	class IdealPaymentGateway_Module extends Core_ModuleBase
	{
		/**
		 * Creates the module information object
		 * @return Core_ModuleInfo
		 */
		protected function createModuleInfo() {
			return new Core_ModuleInfo(
				"iDEAL Payment Gateway Module",
				"Adds custom payment types for iDEAL payment gateway processing",
				"Philip Schalm" );
		}

		public function subscribeEvents() {
			Backend::$events->addEvent('core:onUninitialize', $this, 'check_transaction_status');
		}
		
		public function check_transaction_status() {
			IdealPaymentGateway_Helper::check_statues();
		}
	}
