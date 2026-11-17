import Model from 'flarum/common/Model';

export default class StoryMedia extends Model {
  kind = Model.attribute<'image' | 'video'>('kind');
  mime = Model.attribute<string>('mime');
  width = Model.attribute<number | null>('width');
  height = Model.attribute<number | null>('height');
  durationMs = Model.attribute<number | null>('durationMs');
  url = Model.attribute<string>('url');
}
