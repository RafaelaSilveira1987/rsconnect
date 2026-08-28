# ENT-027 — Inventário canônico das migrations

## Resumo

- Migrations de subida: **96**
- Rollbacks isolados: **1**
- Baseline executável do snapshot: **004_crm.sql**
- Registro canônico: **089_schema_migrations_registry.sql**
- Ordem efetiva: campo `sequence` do `manifest.php`; o prefixo histórico não é mais usado isoladamente para ordenar.

## Numerações históricas duplicadas

- **017**: `017_evolution_qrcode_status.sql` → `017_pre_scheduling_tenant_menus.sql`
- **018**: `018_onboarding_prompt_builder.sql` → `018_pre_schedule_messages_confirmation.sql`
- **019**: `019_implementation_checklist.sql` → `019_pre_schedule_confirmation_rules.sql`
- **020**: `020_pre_schedule_reliable_capture.sql` → `020_queue_team_distribution.sql`
- **021**: `021_campaigns_controlled_broadcasts.sql` → `021_security_system.sql`
- **022**: `022_lgpd_privacy_acceptance.sql` → `022_white_label_basic.sql`
- **023**: `023_operations_monitoring_backup.sql` → `023_white_label_pro.sql`
- **063**: `063_message_governance_evolution_realtime.sql` → `063_message_governance_evolution_realtime_compat.sql`
- `030_google_calendar_availability_modes_rollback.sql` é rollback e não participa do fluxo de subida.

## Inventário

| Seq. | Nº histórico | Tipo | Arquivo | Instruções | SHA-256 |
|---:|:---:|:---:|---|---:|---|
| 1 | 002 | up | `002_companies_users_onboarding.sql` | 9 | `bac029d0358ea3e520a082e273916fd1f5f9e9a573b5ba03749d4d0b4e82f34e` |
| 2 | 003 | up | `003_conversations.sql` | 8 | `1a530d3a9584de967e02ad390fb2f489575ebb9cd6a9ce9631b74ea6c5464217` |
| 3 | 004 | up | `004_crm.sql` | 11 | `f2959a37109514ce0afc3541b5d5ee1dbf876ab7e9cfaf2928dc20fdfb9ab9a5` |
| 4 | 005 | up | `005_ai_automations.sql` | 29 | `09007ee2d01147390187a2217abd80cca9590aae3439773d81ba3a57eb48f44c` |
| 5 | 006 | up | `006_switch_active_agents_to_openai.sql` | 1 | `8782a780e72cb946b1ef74c8276fdd93fbc86efa3ac9755f23f19e7d83d55068` |
| 6 | 007 | up | `007_ai_commercial_rules.sql` | 31 | `374fc9efff5d6f9b37af15a29fa0fb36064654fb16ba539c0166bdc8e10d6220` |
| 7 | 008 | up | `008_conversations_pro_crm_auto.sql` | 18 | `bdd7a686bb0cba3b882b95e5afdb941c8abeadfc4c492ada06e39de9879ad28e` |
| 8 | 009 | up | `009_calendar_appointments.sql` | 4 | `fabb7dae9312253050d98a886ba02eb7eecd8550cc303b6ac0571ccb3b4ee06c` |
| 9 | 010 | up | `010_n8n_tenant_flows.sql` | 3 | `898ec3cd8f14d790c6de39f6d2c44ae2a4e8a0d2e9c467810a3aec888438aec1` |
| 10 | 011 | up | `011_n8n_templates_callbacks.sql` | 3 | `4bb72f1c946b9160bbbe654dd0d0fb465360a2c8b822ea75490e90875effcd24` |
| 11 | 012 | up | `012_saas_billing_plans.sql` | 8 | `039a50b1ececc211da33e2a5a942dfc7f838d277e3d869df46e21f4f9f408f02` |
| 12 | 013 | up | `013_payment_gateways.sql` | 40 | `c8c4ea080f6a292c3d49ce2fde7cadebcd1281974805954d67c4b2b896b41a05` |
| 13 | 014 | up | `014_pagbank_billing_reminders.sql` | 5 | `88a1d053546808064af0e1bd58d6aeb57fec614d2e5fbe11ebc49b2ab585c0bd` |
| 14 | 015 | up | `015_notifications_frontend_billing_templates.sql` | 4 | `801ea840affe60a9ac91b56c8a4cb765da53242d8e78148a0f72a5e2aac0ec0b` |
| 15 | 016 | up | `016_reports_realtime_conversations.sql` | 3 | `2198e0b336a1919e66d074292407ce16fc6dc5a7232cfe2dde390b75ea1b4639` |
| 16 | 017 | up | `017_evolution_qrcode_status.sql` | 32 | `ccca90d4b3fa283cc72880237c6380cafb98babdc60feb966ec2eb4710ef9909` |
| 17 | 017 | up | `017_pre_scheduling_tenant_menus.sql` | 32 | `df02b4b181d34ae472e8f3782091accb98b10afa478dbcdb2add6bc28730964b` |
| 18 | 018 | up | `018_onboarding_prompt_builder.sql` | 11 | `635a885c347525efa28a24f83f82052fdee9203a13946f97061a4f34ef62557b` |
| 19 | 018 | up | `018_pre_schedule_messages_confirmation.sql` | 11 | `55f5aad2bd2feae9ef089c2d8fa2c77af8ed4dc19377aa0c719c992232cb697f` |
| 20 | 019 | up | `019_implementation_checklist.sql` | 3 | `6fd9d930f84bc9af6e6fe5b4f310fd3d471a474aa09db6649c24677786c002d3` |
| 21 | 019 | up | `019_pre_schedule_confirmation_rules.sql` | 1 | `72a2167651afc60eb48a2bbeb6c050961c36e97d7b82c9f5ae3b60fd5fca0d63` |
| 22 | 020 | up | `020_pre_schedule_reliable_capture.sql` | 2 | `bb84096fcd7d9dfab01624343ac06d1c372acefbb379d4234fcb6db9fda95e48` |
| 23 | 020 | up | `020_queue_team_distribution.sql` | 41 | `f2f04a1b595fcabd2b5c8f17bd4196ffc448bf702e17acd85f950089c1d68958` |
| 24 | 021 | up | `021_campaigns_controlled_broadcasts.sql` | 18 | `b22b7f68dbe6fa713f0a1ce48f2095820224da15993c9933f7ae72dd4dca4f09` |
| 25 | 021 | up | `021_security_system.sql` | 7 | `53837365a5997e54d2cb73eb211e36f4df494f6131f81d9ac63cfec1c85f46e1` |
| 26 | 022 | up | `022_lgpd_privacy_acceptance.sql` | 10 | `ec229ee1164211c32ca27e429079e89749cd31d18bead6cd1738af7e4e10c6ce` |
| 27 | 022 | up | `022_white_label_basic.sql` | 67 | `69220ac9266a1f8bfab2df80f8bc11dca782461ab3d8bd07005b09469ebdf73c` |
| 28 | 023 | up | `023_operations_monitoring_backup.sql` | 8 | `8dc2ca68fb30d843e167221a1fa9e1cff7a6d3ce74e90520c9533f97433acdfa` |
| 29 | 023 | up | `023_white_label_pro.sql` | 48 | `e535329f7486b88f4bf959323f747ab804732a71816c9d0ad82c317c91d0952c` |
| 30 | 024 | up | `024_operations_alerts_backup_details.sql` | 20 | `e9327ffef9229c10ef485ddf3e5e4a03516240ae22357ef64156fbd25ad88b97` |
| 31 | 025 | up | `025_commercial_implementation_checklist.sql` | 5 | `bc51688a5051e2db3d4fdd56468cd210e3834466cb06274c7baf72255b7d9142` |
| 32 | 026 | up | `026_fix_implementation_manual_checklist_table.sql` | 1 | `16bd3b3a3c1e02e6d16cc1f6f94887a5f8c094127bc8fe38b72a974050cc37e9` |
| 33 | 027 | up | `027_guided_client_onboarding.sql` | 6 | `ddd3a81dd0dc62eb0d1bcbb364985033ef0869e349829cb9ab0d3397af6a4759` |
| 34 | 028 | up | `028_backup_automation_n8n.sql` | 3 | `fb5ccf849f838f1108207fc879f105b891d9b3066671d01e42f9b13b3d33a553` |
| 35 | 029 | up | `029_smart_calendar_availability_n8n.sql` | 16 | `dad99c0b0defb1ebdb04d00dadb19d710f6eee2a95d582728eb238fea59c630d` |
| 36 | 030 | up | `030_google_calendar_availability_modes.sql` | 42 | `fca60b4ac08efbb5a81b48f59306341dd97a87b8e34431e9889bcfb6882ae29b` |
| 37 | 031 | up | `031_optimize_conversation_messages_index.sql` | 5 | `cce66a516b49b2414553d14595da7d685bd5ed8edd7cfbf2454e1b18e47354cd` |
| 38 | 032 | up | `032_conversation_messages_compact_index.sql` | 11 | `90e2a20ad1828a8f131ad8dab65fc8f5a99e5c881196d81580e1965041d6edba` |
| 39 | 033 | up | `033_customer_company_profile.sql` | 58 | `0609ba4fdee907238fb796f010aa31c9e75fb8c26b23c4b4dbfa07455512a6c5` |
| 40 | 034 | up | `034_notification_preferences_and_alerts.sql` | 3 | `c649520db2e2ed6d1d73058e31591050d2a133d5a8664c725d7e874d1111b115` |
| 41 | 035 | up | `035_admin_company_tracking.sql` | 1 | `9808606f24899edc8496936b4077a70ec89cca9bf415d18834ada8be8396ae8e` |
| 42 | 036 | up | `036_security_access_enforcement.sql` | 22 | `8477f3c2f6072ce91ac1a66695f3fa79915e98f929251e949282c02863df79e7` |
| 43 | 037 | up | `037_admin_commercial_crm_reports.sql` | 6 | `a89fc18cbddcfbaaee9630955ec4a1dac25685ec74c7b5a8b20254ac75db75f6` |
| 44 | 038 | up | `038_ai_reaction_preferences.sql` | 5 | `5acc5c2e963f23e76d2f2112f03000d9dac9f9c75ce481a8dba0d3136d098abf` |
| 45 | 039 | up | `039_tenant_health_diagnostics.sql` | 4 | `1a066cd6adf893c5209a2a34e5c93a5bd7c50db572084dfd7bbb38a4c65d97fa` |
| 46 | 040 | up | `040_conversation_flow_contact_groups.sql` | 9 | `79bb1c5f9ae6dba0cd6ee6ecff90ba442537a281847234afbe30ee933b2f7b0b` |
| 47 | 041 | up | `041_calendar_google_full_cycle.sql` | 31 | `d48a589569b3356fd0bc98f5b8680742948ad8bb10a525eb03336b07e8f6a697` |
| 48 | 042 | up | `042_payment_reconciliation_external_providers.sql` | 18 | `a2709844b45ac392d674d51426e4d96ecd7ccd1cda44ed7fd8798cf8f5e8ff43` |
| 49 | 043 | up | `043_ai_reprocess_schedule.sql` | 3 | `f8424b74040920afa0b641af5891eab4ae00d9434f91269f93b771a1df3528c5` |
| 50 | 044 | up | `044_ai_pending_failures_message_link.sql` | 10 | `c5fdbc695654fcc152593aec0e92b9475e6501254692e7796776048702e7800a` |
| 51 | 045 | up | `045_ai_webhook_ingestion_resilience.sql` | 19 | `a4c5722505bca6cf04e331320107d0fb6fe747cce03fb2f83b923d861764404f` |
| 52 | 046 | up | `046_calendar_conversational_slot_selection.sql` | 22 | `ebfde1248d379275740f7c1782c9b7dfb48d86bbf39f4299786b52f27eca81ad` |
| 53 | 047 | up | `047_backup_automation_reliability.sql` | 75 | `62d661d6bd82eff9bfcc3199f08d523ba176b425dfd458e69b25754527b3f4a8` |
| 54 | 048 | up | `048_reporting_metrics_foundation.sql` | 8 | `68b52933250f5877bc797d8a10b1996d8781650a65f5b950abe960a5f670c906` |
| 55 | 049 | up | `049_operational_resolution_communications.sql` | 25 | `3e6e590faac714abeaad7b650d084a7cc9c4835cb2b58a5e4c8db4b4ec83ea66` |
| 56 | 050 | up | `050_human_takeover_customer_context.sql` | 2 | `848820be3c82b3b519ee15bc814ab7ef4ef6702ed41914659e5e2fcd5fbc193a` |
| 57 | 051 | up | `051_operational_evidence_status.sql` | 1 | `8d2dea8fa8fce07ee2d0e96e85e1e102fd9db854ec8966cae2a3ab3a3121906d` |
| 58 | 052 | up | `052_ai_usage_and_after_hours_recovery.sql` | 10 | `440deeec67d3682af7e38545a5b90e9ff6333dae1effd8769fedf15d30181844` |
| 59 | 053 | up | `053_ai_quota_limit_repair.sql` | 2 | `74545755ba294b4f719e5b86dd24dd8a1f3dc0fdf236a30b46f213d75d5ae2d3` |
| 60 | 054 | up | `054_ai_metrics_and_delivery_telemetry.sql` | 30 | `628f41a80ebe4688589c78efce0789fff9d972e596ae4cdfde9616ac1794a79d` |
| 61 | 055 | up | `055_multi_whatsapp_agent_routing.sql` | 20 | `de29cd5e4106208378aa42c22350c82d56292fea4ef747de5ac0e1c7802dad39` |
| 62 | 056 | up | `056_n8n_agenda_event_contract.sql` | 1 | `3e7786ee342384420749a6fdc03c65fa982d1728a2c256c7ed640a1104f5c878` |
| 63 | 057 | up | `057_calendar_modality_before_availability.sql` | 9 | `05978afc7e5ec165c393cbaa3250b814ad9f4c916ea97a4c803e83c33ac45edd` |
| 64 | 058 | up | `058_client_communication_center.sql` | 45 | `9a1fcb12366a3c6fd0deb6593e6bfe294cb22a432094cead60d86bde2b871cd0` |
| 65 | 059 | up | `059_contact_identity_confidence.sql` | 18 | `6c22a5c6e091d0c3f4f08e75f123f3cc4243e825c1105cff3607c7afcf8a8075` |
| 66 | 060 | up | `060_free_trial_guided_first_access.sql` | 26 | `5c7a36dcd09107fa9c48af2c5dc536f92439f29d4349c8150084a91d6246cd8f` |
| 67 | 061 | up | `061_onboarding_calendar_modes.sql` | 20 | `a4d5d9d67a205747ef8ae69eea05c7e05fb7e3219184c17821916cf0c96d5390` |
| 68 | 062 | up | `062_prompt_studio_and_versions.sql` | 4 | `630d7c22e3031648b35a8740afc85d32a9b4638dff84acfdb78fc501cda72204` |
| 69 | 063 | up | `063_message_governance_evolution_realtime.sql` | 20 | `7d0f3618b1db0222c5168116a89b844a2d56780e5c8d4cf79baad3778a9559c4` |
| 70 | 063 | up | `063_message_governance_evolution_realtime_compat.sql` | 136 | `ad594e060d560990dc4f4d17f1b0169f77ab0d0cbd85bb88f9ff6bf77e73d758` |
| 71 | 064 | up | `064_professional_conversation_assignment_compat.sql` | 72 | `e3c2aba1732bcbf3de544c5bed2b81edcba80574c9c17a859a09c3e74ba610d7` |
| 72 | 065 | up | `065_professional_calendar_profiles_compat.sql` | 18 | `d8b94a8ab2608be368f311ea60cd2c5708c942152adfd114a6365c6fe2a0cabf` |
| 73 | 066 | up | `066_contact_schedule_overlap_guard_compat.sql` | 7 | `3492bc7b4b34172f39483a3f69d696d9bd2cb507dccbec783d4e87d700adf8aa` |
| 74 | 067 | up | `067_operational_history_metrics_compat.sql` | 126 | `cd9386f6c737494cfdb17c8e255d68a743c4692ae2b45352fb184934b9901224` |
| 75 | 068 | up | `068_conversation_service_cycles_compat.sql` | 10 | `fbefbf21f699f68afc32e8f2e6b2e80d9416acc4e936f86650a0f505b768f083` |
| 76 | 069 | up | `069_service_cycle_recovery_compat.sql` | 13 | `76ac20a2ad493e0d65779719d217c0b5d53f284526cd100cafed34981c6857cf` |
| 77 | 070 | up | `070_conversation_cycle_status_sync_compat.sql` | 8 | `785962ca80d37d3912d83c120cb4d05f0b8513b95fb344e8a0a1a353b2905520` |
| 78 | 071 | up | `071_utc_datetime_contract_compat.sql` | 43 | `9a9694854a8e7acdfe01d1484cdaf7d937ff28014905fe2b7963a54d24c6d487` |
| 79 | 072 | up | `072_security_session_webhook_hardening.sql` | 4 | `85d585f81f6d7db79fb581193cf9e8426b23ff9346aab5116e5676e1f429ae5c` |
| 80 | 073 | up | `073_operational_monitoring_alert_delivery.sql` | 90 | `60f91e1ed3b139d9b0535852812e345e5751ab8067d3e15ed1f2f95220d5858d` |
| 81 | 074 | up | `074_conversation_message_attachments.sql` | 2 | `52568076bc183a6b4ef89971d8231a285bcf7c44c3a2fc72f0e611cf7f679f16` |
| 82 | 075 | up | `075_scheduled_reports_and_deliveries.sql` | 8 | `b2ad8794238e6b5a94a6a58880143256d346dd070396aa554c390a3cbb03e464` |
| 83 | 076 | up | `076_evolution_instance_management.sql` | 107 | `9830e62bd7a70bcbed2971074468264677321f0f691b7d4bb485645531229104` |
| 84 | 077 | up | `077_ai_efficiency_foundation.sql` | 46 | `06d0656a2a4a18cd7508ab807b8a76a54aa9efe9fa0e785f2c75ce194227363d` |
| 85 | 078 | up | `078_contact_avatar_refresh.sql` | 6 | `a8b970530cd22ab2e93f6641cc545e662c5b1474620a3e8dc027194c55ae8bdd` |
| 86 | 079 | up | `079_ai_efficiency_phase2_and_report_cleanup.sql` | 43 | `2e69c048f57bd8e2ce008892e1076851f2128acdf49f78261eb354d9ecb611fb` |
| 87 | 080 | up | `080_ai_memory_and_usage_intelligence.sql` | 15 | `c3eb642b797cf49598197325839b823e961b9ed4823653a5a7bc2c6186b3521f` |
| 88 | 081 | up | `081_ai_cost_attribution.sql` | 1 | `520fee70e3a9744d5d3dd8e60505db621f3fdd53f8a0d8baac6d99c94595f284` |
| 89 | 082 | up | `082_ai_budget_governance.sql` | 2 | `cb7764b3f9057568b86fe4aa96babc559a1c1977dcd62cfcda3490d7c013e546` |
| 90 | 083 | up | `083_ai_commercial_margin.sql` | 1 | `305c45cf9b7dfaad12d746ff7206066082af8a3839dfeab252d2590a30751311` |
| 91 | 084 | up | `084_ai_profitability_history.sql` | 3 | `66c113be8ac950fe4cc0895e8285d56e0fe651e26fae47e80d73e4eedfb176f8` |
| 92 | 085 | up | `085_ai_commercial_attention_queue.sql` | 1 | `81fb76587dce8b3818df0cfd4e7b7921b31a894e8f39ce526cdaa7e44ebdc9ae` |
| 93 | 086 | up | `086_plan_ai_mode_and_commitment.sql` | 27 | `a5792e12d27a40f0ba37293c311399301f8b490cb40d240f7b7ff02db2bb2dab` |
| 94 | 087 | up | `087_webhook_security_events.sql` | 1 | `3756494dff3ff872f7a549528631dc22521480f8fd0003cf6a49e56c758aa496` |
| 95 | 088 | up | `088_payment_reconciliation_schema_compat.sql` | 17 | `5124ae77667c617b2ac98f6b5f9f2fc082106faa5e4ca03f7a343f2dc31e64c5` |
| 96 | 089 | up | `089_schema_migrations_registry.sql` | 1 | `544019014962a7dc761ab92cdfc3e1d8a7bf566be3c56a2d37e9593c7d108237` |
| — | 030 | rollback | `030_google_calendar_availability_modes_rollback.sql` | 1 | `84650c271b8149ad352557394a71e198f92ed0a842fb53b96bc68f489b866b2f` |
