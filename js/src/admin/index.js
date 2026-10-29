import { Admin } from 'flarum/common/extenders';
import SurveyorPage from './SurveyorPage';
import { t } from './util';

const key = (name) => `ernestdefoe-surveyor.${name}`;

// `extra` is a function too: nothing here may call the translator before boot.
const field =
  (name, type, extra = () => ({})) =>
  () => ({
    setting: key(name),
    type,
    label: t(`settings.${name}_label`),
    help: t(`settings.${name}_help`),
    ...extra(),
  });

// Callbacks, not values: this module runs before the app has booted. The
// settings are shown in the workspace's Settings section.
export const extend = [
  new Admin()
    .page(SurveyorPage)
    .setting(
      field('audit_frequency', 'select', () => ({
        options: {
          daily: t('settings.frequency_daily'),
          weekly: t('settings.frequency_weekly'),
          monthly: t('settings.frequency_monthly'),
          manual: t('settings.frequency_manual'),
        },
        default: 'weekly',
      }))
    )
    .setting(field('max_pages', 'number', () => ({ min: 10, max: 20000 })))
    .setting(field('pages_per_minute', 'number', () => ({ min: 1, max: 120 })))
    .setting(field('timeout', 'number', () => ({ min: 2, max: 20 })))
    .setting(field('keep_runs', 'number', () => ({ min: 2, max: 100 })))
    .setting(
      field('google_refresh', 'select', () => ({
        options: { daily: t('settings.frequency_daily'), weekly: t('settings.frequency_weekly'), off: t('settings.google_refresh_off') },
        default: 'daily',
      }))
    )
    .setting(field('inspect_daily', 'number', () => ({ min: 1, max: 2000 })))
    .setting(
      field('speed_schedule', 'select', () => ({
        options: { off: t('settings.speed_schedule_off'), weekly: t('settings.frequency_weekly') },
        default: 'off',
      }))
    )
    .setting(field('speed_pages', 'number', () => ({ min: 1, max: 100 })))
    .setting(field('speed_daily', 'number', () => ({ min: 1, max: 2000 })))
    .setting(field('related_enabled', 'boolean'))
    .setting(field('related_count', 'number', () => ({ min: 1, max: 10 })))
    .setting(field('ai_enabled', 'boolean'))
    .setting(field('ai_model', 'text', () => ({ placeholder: 'claude-haiku-5-5' })))
    .setting(field('ai_daily', 'number', () => ({ min: 1, max: 1000 })))
    .setting(field('ai_monthly_tokens', 'number', () => ({ min: 10000, max: 50000000 }))),
];
