import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request } from '../util';
import { when, number, link } from './format';

/** IndexNow: tells Bing, Yandex, Seznam, Naver and others when a discussion changes. */
export default class IndexNow extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    this.urls = Stream('');
    this.result = null;
    this.load('GET', '');
  }

  load(method, path) {
    this.busy = true;
    return request(method, `/indexnow${path}`).then((data) => {
      this.data = data;
      this.busy = false;
      m.redraw();
    });
  }

  submit() {
    this.busy = true;
    request('POST', '/indexnow/submit', { urls: this.urls() }).then((result) => {
      this.result = result;
      this.busy = false;
      if (result.status === 200 || result.status === 202) this.urls('');
      this.load('GET', '');
    });
  }

  view() {
    const d = this.data;
    if (!d) return <LoadingIndicator />;

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('indexnow.help')}</p>

        <div className="SurveyorRun">
          <div className="SurveyorRun-text">
            {d.enabled ? t('indexnow.status_on', { pending: d.pending }) : t('indexnow.status_off')}
            {d.backoffUntil ? <div className="SurveyorWarn">{t('indexnow.backoff', { when: when(d.backoffUntil) })}</div> : null}
          </div>
          {d.enabled ? (
            <Button className="Button" loading={this.busy} onclick={() => this.load('POST', '/disable')}>
              {t('indexnow.turn_off')}
            </Button>
          ) : (
            <Button className="Button Button--primary" loading={this.busy} onclick={() => this.load('POST', '/enable')}>
              {t('indexnow.turn_on')}
            </Button>
          )}
        </div>

        {d.key ? (
          <div className="Form-group">
            <label>{t('indexnow.key')}</label>
            <div className="SurveyorKey">
              <code>{d.key}</code>
              {link(d.keyUrl, t('indexnow.key_file'))}
              <Button
                className="Button Button--link"
                onclick={() => {
                  if (confirm(t('indexnow.new_key_confirm'))) this.load('POST', '/key');
                }}
              >
                {t('indexnow.new_key')}
              </Button>
            </div>
            <p className="helpText">{t('indexnow.key_help')}</p>
          </div>
        ) : null}

        {d.enabled ? (
          <div className="Form-group">
            <label for="surveyor-indexnow-urls">{t('indexnow.manual')}</label>
            <textarea
              id="surveyor-indexnow-urls"
              className="FormControl SurveyorMono"
              rows="4"
              bidi={this.urls}
              placeholder={app.forum.attribute('baseUrl') + '/d/1'}
            />
            <p className="helpText">{t('indexnow.manual_help')}</p>
            <Button className="Button Button--primary" loading={this.busy} disabled={!this.urls().trim()} onclick={() => this.submit()}>
              {t('indexnow.send')}
            </Button>
            {this.result ? (
              <p className="SurveyorResult">
                {t('indexnow.result', { status: this.result.status || '–', sent: this.result.sent.length })}
                {this.result.refused.length ? ' ' + t('indexnow.refused', { count: this.result.refused.length }) : ''}
              </p>
            ) : null}
          </div>
        ) : null}

        <h3>{t('indexnow.log')}</h3>
        {d.log.length === 0 ? <p className="SurveyorEmpty">{t('indexnow.log_empty')}</p> : null}
        {d.log.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('indexnow.col_when')}</th>
                <th>{t('indexnow.col_count')}</th>
                <th>{t('indexnow.col_status')}</th>
                <th>{t('indexnow.col_detail')}</th>
              </tr>
            </thead>
            <tbody>
              {d.log.map((row) => (
                <tr>
                  <td data-label={t('indexnow.col_when')}>
                    {when(row.at)} <span className="SurveyorMuted">{t(`indexnow.origin_${row.origin}`)}</span>
                  </td>
                  <td className="SurveyorTable-num" data-label={t('indexnow.col_count')}>
                    {number(row.count)}
                  </td>
                  <td data-label={t('indexnow.col_status')}>
                    <span className={`SurveyorBadge SurveyorBadge--${row.status === 200 || row.status === 202 ? 'ok' : 'broken'}`}>
                      {row.status || '–'}
                    </span>
                  </td>
                  <td className="SurveyorMuted" data-label={t('indexnow.col_detail')}>
                    {row.status === 200 || row.status === 202 ? row.sample.join(', ') : row.message || t(`indexnow.status_${row.status}`)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    );
  }
}
