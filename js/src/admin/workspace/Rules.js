import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Stream from 'flarum/common/utils/Stream';
import { t, request, quietRequest } from '../util';

const blank = () => ({ type: 'wildcard', pattern: '', action: 'exclude', scope: 'both', enabled: true, note: '' });

/** Address patterns that keep areas out of the audit and IndexNow (or limit them to some areas). */
export default class Rules extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.rules = null;
    this.form = blank();
    this.error = null;
    this.testPath = Stream('');
    this.testResult = null;
    this.load();
  }

  load() {
    request('GET', '/rules').then((response) => {
      this.rules = response.data;
      m.redraw();
    });
  }

  save() {
    this.saving = true;
    this.error = null;
    const id = this.form.id;
    quietRequest(id ? 'PATCH' : 'POST', id ? `/rules/${id}` : '/rules', this.form)
      .then(() => {
        this.form = blank();
        this.saving = false;
        this.load();
      })
      .catch((error) => {
        this.saving = false;
        this.error = error.response?.errors?.[0]?.detail || t('rules.error');
        m.redraw();
      });
  }

  remove(rule) {
    request('DELETE', `/rules/${rule.id}`).then(() => this.load());
  }

  toggle(rule) {
    request('PATCH', `/rules/${rule.id}`, { enabled: !rule.enabled }).then(() => this.load());
  }

  test() {
    request('POST', '/rules/test', { path: this.testPath() }).then((result) => {
      this.testResult = result;
      m.redraw();
    });
  }

  view() {
    const f = this.form;
    const set = (key) => (value) => (f[key] = value);

    return (
      <div className="SurveyorSection">
        <p className="helpText">{t('rules.help')}</p>

        {this.rules === null ? <LoadingIndicator /> : null}
        {this.rules && this.rules.length === 0 ? <p className="SurveyorEmpty">{t('rules.empty')}</p> : null}
        {this.rules && this.rules.length ? (
          <table className="SurveyorTable">
            <thead>
              <tr>
                <th>{t('rules.col_pattern')}</th>
                <th>{t('rules.col_action')}</th>
                <th>{t('rules.col_scope')}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              {this.rules.map((rule) => (
                <tr className={rule.enabled ? '' : 'SurveyorRow--off'}>
                  <td data-label={t('rules.col_pattern')}>
                    <span className="SurveyorBadge">{t(`rules.type_${rule.type}`)}</span> <code className="SurveyorMono">{rule.pattern}</code>
                    {rule.note ? <div className="SurveyorMuted">{rule.note}</div> : null}
                  </td>
                  <td data-label={t('rules.col_action')}>{t(`rules.action_${rule.action}`)}</td>
                  <td data-label={t('rules.col_scope')}>{t(`rules.scope_${rule.scope}`)}</td>
                  <td className="SurveyorTable-actions">
                    <Button className="Button Button--link" onclick={() => this.toggle(rule)}>
                      {rule.enabled ? t('rules.disable') : t('rules.enable')}
                    </Button>
                    <Button className="Button Button--link" onclick={() => (this.form = { ...rule, note: rule.note || '' })}>
                      {t('rules.edit')}
                    </Button>
                    <Button className="Button Button--link" onclick={() => this.remove(rule)}>
                      {t('rules.delete')}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}

        <h3>{f.id ? t('rules.edit_heading') : t('rules.add_heading')}</h3>
        <div className="SurveyorForm">
          <div className="Form-group">
            <label>{t('rules.type')}</label>
            <Select
              value={f.type}
              onchange={set('type')}
              options={{ exact: t('rules.type_exact'), wildcard: t('rules.type_wildcard'), regex: t('rules.type_regex') }}
            />
            <p className="helpText">{t(`rules.type_${f.type}_help`)}</p>
          </div>
          <div className="Form-group">
            <label for="surveyor-rule-pattern">{t('rules.pattern')}</label>
            <input
              id="surveyor-rule-pattern"
              className="FormControl SurveyorMono"
              maxlength="300"
              value={f.pattern}
              oninput={(e) => (f.pattern = e.target.value)}
              placeholder={f.type === 'regex' ? '^/d/\\d+-test' : '/t/staff*'}
            />
          </div>
          <div className="Form-group">
            <label>{t('rules.action')}</label>
            <Select value={f.action} onchange={set('action')} options={{ exclude: t('rules.action_exclude'), include: t('rules.action_include') }} />
            <p className="helpText">{t(`rules.action_${f.action}_help`)}</p>
          </div>
          <div className="Form-group">
            <label>{t('rules.scope')}</label>
            <Select
              value={f.scope}
              onchange={set('scope')}
              options={{ both: t('rules.scope_both'), audit: t('rules.scope_audit'), indexnow: t('rules.scope_indexnow') }}
            />
          </div>
          <div className="Form-group">
            <label for="surveyor-rule-note">{t('rules.note')}</label>
            <input id="surveyor-rule-note" className="FormControl" maxlength="255" value={f.note} oninput={(e) => (f.note = e.target.value)} />
          </div>
          <div className="Form-group">
            <Switch state={f.enabled} onchange={set('enabled')}>
              {t('rules.enabled')}
            </Switch>
          </div>
          {this.error ? <p className="SurveyorWarn">{this.error}</p> : null}
          <div className="Form-group">
            <Button className="Button Button--primary" loading={this.saving} onclick={() => this.save()}>
              {t('rules.save')}
            </Button>
            {f.id ? (
              <Button className="Button Button--link" onclick={() => (this.form = blank())}>
                {t('rules.cancel')}
              </Button>
            ) : null}
          </div>
        </div>

        <h3>{t('rules.test_heading')}</h3>
        <div className="SurveyorToolbar">
          <input className="FormControl SurveyorMono SurveyorToolbar-search" bidi={this.testPath} placeholder="/d/1-hello" />
          <Button className="Button" onclick={() => this.test()}>
            {t('rules.test')}
          </Button>
        </div>
        {this.testResult ? (
          this.testResult.offsite ? (
            <p className="SurveyorWarn">{t('rules.test_offsite')}</p>
          ) : (
            <ul className="SurveyorResult">
              <li>
                {t('rules.test_audit', { result: this.testResult.audit ? t(`rules.reason_${this.testResult.audit}`) : t('rules.reason_none') })}
              </li>
              <li>
                {t('rules.test_indexnow', {
                  result: this.testResult.indexnow ? t(`rules.reason_${this.testResult.indexnow}`) : t('rules.reason_none'),
                })}
              </li>
              <li>{t('rules.test_matches', { count: this.testResult.rules.length })}</li>
            </ul>
          )
        ) : null}
      </div>
    );
  }
}
