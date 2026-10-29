import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request } from '../util';
import { number, link } from './format';

/** Orphan and weakly linked pages, hubs, and related discussions worth linking. */
export default class InternalLinks extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    this.discussion = Stream('');
    this.related = null;
    request('GET', '/internal-links').then((data) => {
      this.data = data;
      m.redraw();
    });
  }

  lookup() {
    request('GET', '/related', null, { discussion: this.discussion() }).then((data) => {
      this.related = data;
      m.redraw();
    });
  }

  list(key, rows, extra) {
    return (
      <div className="SurveyorGroup">
        <h3>{t(`internal.${key}`)}</h3>
        <p className="helpText">{t(`internal.${key}_help`)}</p>
        {rows.length === 0 ? <p className="SurveyorEmpty">{t('internal.none')}</p> : null}
        <ul>
          {rows.map((row) => (
            <li>
              {link(app.forum.attribute('baseUrl') + (row.path === '/' ? '' : row.path), row.title || row.path)}{' '}
              {extra ? <span className="SurveyorMuted">{extra(row)}</span> : null}
            </li>
          ))}
        </ul>
      </div>
    );
  }

  view() {
    const d = this.data;
    const enabled = app.data.settings['ernestdefoe-surveyor.related_enabled'] === '1';

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('internal.help')}</p>

        <h3>{t('internal.related_heading')}</h3>
        <div className="SurveyorToolbar">
          <input className="FormControl SurveyorMono SurveyorToolbar-search" bidi={this.discussion} placeholder="/d/12 or 12" />
          <Button className="Button" onclick={() => this.lookup()}>
            {t('internal.related_find')}
          </Button>
        </div>
        {this.related && !this.related.discussion ? <p className="SurveyorWarn">{t('internal.related_not_found')}</p> : null}
        {this.related && this.related.discussion ? (
          this.related.data.length ? (
            <ul className="SurveyorQueries">
              {this.related.data.map((r) => (
                <li>
                  {link(r.url, r.title)}{' '}
                  <span className="SurveyorMuted">{t('internal.related_why', { tags: r.sharedTags, words: r.sharedWords.join(', ') || '–' })}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="SurveyorEmpty">{t('internal.related_none')}</p>
          )
        ) : null}
        <p className="helpText">{enabled ? t('internal.block_on') : t('internal.block_off')}</p>

        {d === null ? <LoadingIndicator /> : null}
        {d ? (
          <>
            {this.list('orphans', d.orphans)}
            {this.list('weak', d.weak)}
            {this.list('most_linked', d.mostLinked, (row) => t('internal.inbound', { count: number(row.inbound) }))}
            {this.list('most_linking', d.mostLinking, (row) => t('internal.outbound', { count: number(row.linksOut) }))}
          </>
        ) : null}
      </div>
    );
  }
}
