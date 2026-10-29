import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import { t, request } from '../util';
import { when, number, score, link } from './format';

const yesNo = (value) => (value === null || value === undefined ? '–' : value ? t('common.yes') : t('common.no'));

/** Everything the audit recorded for one page, as plain text. */
export default class PageDetail extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.page = null;
    request('GET', `/pages/${this.attrs.id}`).then((response) => {
      this.page = response.data;
      m.redraw();
    });
  }

  view() {
    const p = this.page;
    if (!p) return <LoadingIndicator />;

    const rows = [
      ['url', link(p.url)],
      ['final_url', p.finalUrl && p.finalUrl !== p.url ? link(p.finalUrl) : '–'],
      ['title', p.title ? `${p.title} (${p.titleLength})` : '–'],
      ['description', p.description ? `${p.description} (${p.descriptionLength})` : '–'],
      ['canonical', p.canonical ? [link(p.canonical), ' ', p.canonicalSelf ? t('page.canonical_self') : t('page.canonical_other')] : '–'],
      ['noindex', yesNo(p.noindex)],
      ['h1', number(p.h1Count)],
      ['words', number(p.wordCount)],
      ['links_out', number(p.linksOut)],
      ['inbound', number(p.inbound)],
      ['images_no_alt', number(p.imagesNoAlt)],
      ['structured_data', p.structuredData ? t(`page.sd_${p.structuredData}`) : '–'],
      ['response', p.responseMs !== null ? t('page.ms', { ms: p.responseMs }) : '–'],
      ['bytes', number(p.bytes)],
      ['found_via', t(`page.via_${p.foundVia}`)],
      ['error', p.error ? t(`page.error_${p.error}`) : '–'],
    ];

    return (
      <div className="SurveyorDetail">
        {p.issues.length ? (
          <ul className="SurveyorDetail-issues">
            {p.issues.map((code) => (
              <li>
                <strong>{t(`issues.${code}`)}</strong> — {t(`issues.${code}_fix`)}
              </li>
            ))}
          </ul>
        ) : p.score !== null ? (
          <p>{t('page.no_issues')}</p>
        ) : null}

        <dl className="SurveyorDetail-fields">{rows.map(([key, value]) => [<dt>{t(`page.${key}`)}</dt>, <dd>{value}</dd>])}</dl>

        {p.history.length > 1 ? (
          <div className="SurveyorDetail-history">
            <h4>{t('page.history')}</h4>
            <ol>
              {p.history.map((h) => (
                <li>
                  {score(h.score)} <span className="SurveyorMuted">{when(h.at)}</span>
                </li>
              ))}
            </ol>
          </div>
        ) : null}
      </div>
    );
  }
}
