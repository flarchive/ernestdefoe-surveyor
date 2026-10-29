import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request } from '../util';
import { number } from './format';

const KNOWN = [
  'off',
  'not_public',
  'daily_cap',
  'monthly_cap',
  'minute_cap',
  'paused',
  'no_key',
  'refused',
  'empty',
  'human_written',
  'not_applied',
  'changed_since',
];
const errorText = (error) => (KNOWN.includes(error) ? t(`ai.error_${error}`) : t('ai.error_other', { message: error }));

/** Optional AI-written descriptions: preview, edit, apply into FoF SEO, undo. */
export default class Ai extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.data = null;
    this.items = [];
    this.all = false;
    this.key = Stream('');
    this.drafts = {};
    this.errors = {};
    this.load('GET', '/ai');
  }

  load(method, path, body) {
    request(method, path, body).then((data) => {
      this.data = data;
      if (data.enabled) this.candidates();
      m.redraw();
    });
  }

  candidates() {
    request('GET', '/ai/candidates', null, { all: this.all ? 1 : 0 }).then((response) => {
      this.items = response.data;
      m.redraw();
    });
  }

  preview(item) {
    item.busy = true;
    request('POST', '/ai/preview', { id: item.id }).then((result) => {
      item.busy = false;
      this.errors[item.id] = result.error;
      if (result.suggestion) {
        item.suggestion = result.suggestion;
        item.status = 'suggested';
        this.drafts[item.id] = result.suggestion;
      }
      m.redraw();
    });
  }

  apply(item) {
    item.busy = true;
    request('POST', '/ai/apply', { id: item.id, text: this.drafts[item.id] ?? item.suggestion }).then((result) => {
      item.busy = false;
      this.errors[item.id] = result.error;
      if (!result.error) {
        item.description = this.drafts[item.id] ?? item.suggestion;
        item.status = 'applied';
      }
      m.redraw();
    });
  }

  undo(item) {
    request('POST', '/ai/undo', { id: item.id }).then((result) => {
      this.errors[item.id] = result.error;
      if (!result.error) item.status = 'undone';
      this.candidates();
    });
  }

  view() {
    const d = this.data;
    if (!d) return <LoadingIndicator />;

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('ai.help')}</p>

        <div className="SurveyorRun">
          <div className="SurveyorRun-text">
            {d.enabled ? t('ai.on', { model: d.model }) : t('ai.off')}
            <div className="SurveyorMuted">
              {t('ai.usage', {
                today: d.today,
                daily: d.dailyCap,
                tokens: number(d.usage.input + d.usage.output),
                monthly: number(d.monthlyTokens),
                cost: d.usage.cost.toFixed(2),
              })}
            </div>
          </div>
          <Button className="Button" onclick={() => this.attrs.go('settings')}>
            {t('ai.settings')}
          </Button>
        </div>

        <h3>{t('ai.key_heading')}</h3>
        <p className="helpText">{d.hasKey ? t('ai.key_set') : t('ai.key_help')}</p>
        <div className="SurveyorToolbar">
          <input
            className="FormControl SurveyorMono SurveyorToolbar-search"
            type="password"
            autocomplete="off"
            bidi={this.key}
            placeholder="sk-ant-…"
          />
          <Button className="Button" onclick={() => (this.load('POST', '/ai/key', { key: this.key() }), this.key(''))}>
            {this.key() ? t('ai.key_save') : t('ai.key_remove')}
          </Button>
        </div>
        {d.keyRejected ? <p className="SurveyorWarn">{t('ai.key_rejected')}</p> : null}

        {d.enabled && d.hasKey ? (
          <>
            <h3>{t('ai.candidates')}</h3>
            <div className="SurveyorToolbar">
              <Button
                className="Button"
                onclick={() => {
                  this.all = !this.all;
                  this.candidates();
                }}
              >
                {this.all ? t('ai.show_short') : t('ai.show_all')}
              </Button>
            </div>
            {this.items.length === 0 ? <p className="SurveyorEmpty">{t('ai.none')}</p> : null}
            {this.items.map((item) => (
              <div className="SurveyorLink">
                <div className="SurveyorLink-head">
                  <strong>{item.title}</strong>
                  {item.status ? <span className="SurveyorBadge">{t(`ai.status_${item.status}`)}</span> : null}
                </div>
                <div className="SurveyorMuted">{t('ai.current', { text: item.description || t('ai.no_description') })}</div>
                {item.suggestion && item.status === 'suggested' ? (
                  <div className="Form-group">
                    <textarea
                      className="FormControl"
                      rows="2"
                      maxlength="160"
                      value={this.drafts[item.id] ?? item.suggestion}
                      oninput={(e) => (this.drafts[item.id] = e.target.value)}
                    />
                    <span className="SurveyorMuted">{t('ai.chars', { count: (this.drafts[item.id] ?? item.suggestion).length })}</span>
                  </div>
                ) : null}
                <div className="SurveyorLink-actions">
                  <Button className="Button" loading={item.busy} onclick={() => this.preview(item)}>
                    {item.suggestion ? t('ai.again') : t('ai.preview')}
                  </Button>
                  {item.suggestion && item.status === 'suggested' ? (
                    <Button className="Button Button--primary" loading={item.busy} onclick={() => this.apply(item)}>
                      {t('ai.apply')}
                    </Button>
                  ) : null}
                  {item.status === 'applied' ? (
                    <Button className="Button" onclick={() => this.undo(item)}>
                      {t('ai.undo')}
                    </Button>
                  ) : null}
                </div>
                {this.errors[item.id] ? <p className="SurveyorWarn">{errorText(this.errors[item.id])}</p> : null}
              </div>
            ))}
          </>
        ) : null}
      </div>
    );
  }
}
