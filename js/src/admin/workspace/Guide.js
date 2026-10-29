import Component from 'flarum/common/Component';
import { t } from '../util';

const TOPICS = [
  'companions',
  'dashboard',
  'audit',
  'score',
  'links',
  'search',
  'google_key',
  'opportunities',
  'speed',
  'regressions',
  'internal',
  'ai',
  'indexnow',
  'rules',
  'diagnostics',
  'settings',
  'privacy',
];
const STEPS = { google_key: 7 };

/** Plain-words help for each section. */
export default class Guide extends Component {
  view() {
    return (
      <div className="SurveyorSection SurveyorGuide">
        {TOPICS.map((topic) => (
          <section id={`surveyor-guide-${topic}`}>
            <h3>{t(`guide.${topic}_title`)}</h3>
            {STEPS[topic] ? (
              <ol>
                {Array.from({ length: STEPS[topic] }, (_, i) => (
                  <li>{t(`guide.${topic}_step_${i + 1}`)}</li>
                ))}
              </ol>
            ) : (
              <p>{t(`guide.${topic}_body`)}</p>
            )}
          </section>
        ))}
      </div>
    );
  }
}
