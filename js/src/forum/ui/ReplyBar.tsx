import Component, { type ComponentAttrs } from 'flarum/common/Component';
import classList from 'flarum/common/utils/classList';
import type Story from '../models/Story';
import { tx } from '../util';
import { replyChannel, sendReply } from './reply';

interface ReplyBarAttrs extends ComponentAttrs {
  story: Story;
  name: string;
  /** The reaction the button shows: the viewer's own, or the first on offer. */
  reaction: string;
  reacting: boolean;
  onpause: (paused: boolean) => void;
  onsay: (message: string) => void;
  onreact: () => void;
}

/**
 * The bottom of someone else's story: a field to reply privately (when Parley or Messages can carry it)
 * and the reaction button. The story pauses while the field has focus.
 */
export default class ReplyBar extends Component<ReplyBarAttrs> {
  private text = '';

  view() {
    const { story } = this.attrs;
    const channel = replyChannel();
    if (!story.canReact() && !channel) return null;

    return (
      <div className="GlimpseViewer-reply">
        {channel ? this.field() : <span className="GlimpseViewer-spacer" />}
        {story.canReact() ? (
          <button
            type="button"
            className={classList('GlimpseViewer-react', story.myReaction() && 'is-active')}
            aria-label={tx('viewer.react')}
            aria-expanded={this.attrs.reacting ? 'true' : 'false'}
            onclick={() => this.attrs.onreact()}
          >
            {this.attrs.reaction}
          </button>
        ) : null}
        {channel && this.text.trim() ? (
          <button type="button" className="GlimpseViewer-send" aria-label={tx('viewer.send')} onclick={() => this.send()}>
            <i className="fas fa-paper-plane" aria-hidden="true" />
          </button>
        ) : null}
      </div>
    );
  }

  private field() {
    return (
      <input
        className="GlimpseViewer-input"
        placeholder={tx('viewer.reply_placeholder', { name: this.attrs.name })}
        aria-label={tx('viewer.reply_label')}
        maxlength={500}
        value={this.text}
        onfocus={() => this.attrs.onpause(true)}
        onblur={() => {
          if (!this.text) this.attrs.onpause(false);
        }}
        oninput={(e: InputEvent) => {
          this.text = (e.target as HTMLInputElement).value;
        }}
        onkeydown={(e: KeyboardEvent) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            this.send();
          }
        }}
      />
    );
  }

  private send(): void {
    const text = this.text.trim();
    if (!text) return;
    this.text = '';
    (document.activeElement as HTMLElement | null)?.blur();
    sendReply(this.attrs.story, text)
      .then(() => this.attrs.onsay(tx('viewer.reply_sent', { name: this.attrs.name })))
      .catch(() => this.attrs.onsay(tx('viewer.reply_failed')))
      .finally(() => this.attrs.onpause(false));
  }
}
