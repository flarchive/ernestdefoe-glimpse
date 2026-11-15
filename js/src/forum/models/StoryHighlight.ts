import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Story from './Story';

export default class StoryHighlight extends Model {
  title = Model.attribute<string>('title');
  position = Model.attribute<number>('position');
  user = Model.hasOne<User>('user');
  stories = Model.hasMany<Story>('stories');
}
