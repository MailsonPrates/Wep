import Fluent from "./fluent";

/**
 * @param {object} config 
 * @param {array} config.routes
 * @param {string} config.routes.url
 * @param {string} config.routes.type
 * @param {string} config.routes.method
 * @param {bool} config.routes.resource
 * 
 */
export default function apiFactory(config={}){

    const routes = config.routes || [];

    return Fluent({
        routes
    });
}