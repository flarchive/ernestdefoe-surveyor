import app from 'flarum/admin/app';
import humanTime from 'flarum/common/helpers/humanTime';
import { t } from '../util';

export const when = (iso) => (iso ? humanTime(new Date(iso)) : t('common.never'));

export const number = (n) => (n === null || n === undefined ? '–' : Number(n).toLocaleString(app.translator.getLocale?.() || undefined));

/** A score as a coloured pill; text only. */
export const score = (value) =>
  value === null || value === undefined ? (
    <span className="SurveyorScore SurveyorScore--none">–</span>
  ) : (
    <span className={`SurveyorScore SurveyorScore--${value >= 80 ? 'good' : value >= 50 ? 'fair' : 'poor'}`}>{value}</span>
  );

export const classification = (value) =>
  value ? (
    <span className={`SurveyorBadge SurveyorBadge--${value}`}>{t(`classes.${value}`)}</span>
  ) : (
    <span className="SurveyorBadge">{t('classes.pending')}</span>
  );

/** An address shown as text, opening in a new tab. Never rendered as markup. */
export const link = (url, label) => (
  <a href={url} target="_blank" rel="noopener noreferrer nofollow" className="SurveyorMono">
    {label || url}
  </a>
);
