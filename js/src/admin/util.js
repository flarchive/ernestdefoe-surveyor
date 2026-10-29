import app from 'flarum/admin/app';

export const t = (key, params) => app.translator.trans(`ernestdefoe-surveyor.admin.${key}`, params);

export const api = (path) => `${app.forum.attribute('apiUrl')}/surveyor${path}`;

export const request = (method, path, body, params) => app.request({ method, url: api(path), body, params });

/** For forms that show the error themselves: no second alert at the top of the page. */
export const quietRequest = (method, path, body) =>
  app.request({
    method,
    url: api(path),
    body,
    errorHandler: (error) => {
      throw error;
    },
  });
