/**
 * @todo
 * - Add testes automatizados
 * 
 * @param {object} configs 
 */
export default function Fluent(configs=[]){

    const routes = configs.routes || [];
    const defaultMethods = ['delete', 'create', 'update', 'get'];
    const routesByMethod = {};

    let State = {};

    let Api = {
        ajax: props => {
            State.ajax = props || {};
            return Api;
        },

        where: function(){

            let args = Core.getArgs(arguments);
            args.unshift('where');
            return Core.setCondition.apply(null, args);   
        },

        or: function(){
            let args = Core.getArgs(arguments);
            args.unshift('or');
            return Core.setCondition.apply(null, args); 
        },

        andOr: function(){
            let args = Core.getArgs(arguments);
            args.unshift('andOr');
            return Core.setCondition.apply(null, args); 
        },

        orderBy: function(){
            let args = Core.getArgs(arguments);

            let argsCount = args.length;
            let haSingleArg = argsCount === 1;

            if ( !argsCount ) return Api;

            let firstArgType = $.type(args[0]);

            // .orderBy("nome")
            if ( haSingleArg && firstArgType == 'string' ){
                State.orderBy = [args[0]];
                return Api;
            }

            if ( firstArgType == 'array' ){
                args = args[0];
                argsCount = args.length;
            }

            let lastArg = args[argsCount - 1];
            let lastIsSort = typeof lastArg == 'string' && ['desc', 'asc'].includes(lastArg.toLowerCase());
            
            let argsList = args;

            if ( lastIsSort ){

                // orderBy("nome", "...", "desc")
                argsList = argsList.slice(0, argsCount - 1);

                State.orderBy = [argsList, lastArg];

                return Api;
            }

            State.orderBy = argsList;

            return Api;
        },

        limit: function(){
            let args = Core.getArgs(arguments);
            let argsCount = args.length;
            let haSingleArg = argsCount === 1;

            if ( !argsCount ) return Api;

            let isSingleList = Array.isArray(args[0]);

            if ( haSingleArg ){
               State.limit = isSingleList 
                ? args[0] 
                : [args[0]];
               return Api;
            }

            if ( argsCount === 2 ){
                State.limit = args;
            }

            return Api;
        },

        page: function(page=1){
            State.page = page;
            return Api;
        },

        /**
         * @example
         * 
         * .leftJoin('users', 'id', 'users.id')
         * 
         * leftJoin: {
         *  'orders': {'id': 'users.id'},
         * }
         *          
         * leftJoin: {
         *  'orders': ['id', 'users.id'],
         *  'products': ['id', 'orders.product_id'],
         * }
         * 
         */
        leftJoin: function(){

            let args = Core.getArgs(arguments);
            let argsCount = args.length;

            if ( !argsCount ) return Api;

            let firstArg = args[0];

            let isVerbose = $.type(args[0]) == "object";

            // console.log({args, isVerbose, firstArg})

            if ( isVerbose ){

                let tables = firstArg;
                Object.keys(firstArg).forEach(table => {
                    let on = tables[table];

                    // 'orders': {'id': 'users.id'},
                    if ( $.type(on) == "object"  ){ 

                        let keys = Object.keys(on);
                        let hasMultiples = keys.length > 1;

                        if ( hasMultiples ){
                            keys.forEach(key => {
                                let value = on[key];
                                State.leftJoin.push([table, [key, value]]);
                            })

                        } else {
                            let key = keys[0];
                            let value = on[key];
                            State.leftJoin.push([table, key, value]);
                        }

                        return;
                    }

                    let tableField = on[0];
                    let joinField = on[1];
                    
                    State.leftJoin.push([table, tableField, joinField]);
                });

                return Api;
            }

            let table = firstArg;
            let conditions = args.slice(1);
            let isMultiplesConditions = Array.isArray(conditions[0]) && conditions.length > 1;

            if ( isMultiplesConditions ){
                State.leftJoin.push([table, [].concat(conditions)]);

            } else {
                State.leftJoin.push([table, conditions[0], conditions[1]]);
            }

            //console.log({conditions, isMultiplesConditions})

            return Api;
        },

        onDuplicateUpdate: function(){
            let args = Core.getArgs(arguments);
            let argsCount = args.length;

            if ( !argsCount ) return Api;

            let fields = args;

            if ( argsCount == 1 && $.type(args[0]) === "array" ){
                fields = args[0];
            }

            State.onDuplicateUpdate = fields;

            return Api;
        }
    };

    let ResponseApi = {};

    const Core = {

        init: () => {

            Core.resetState();
            Core.setRoutes(routes);
        },

        setRoutes: routes => {

            let hasDefaultRoutes = false;

            routes.forEach(route => {

                let methodName = route.method;
                let group = route.resource;
                
                /**
                 * Assumo que todas as rotas que tem a prop group
                 * são rotas de modulos vendor, então não precisa
                 * de métodos padrões como condições, filtros e etc
                 */
                if ( group ){

                    if ( !ResponseApi[group] ){
                        ResponseApi[group] = {};
                    }

                    let methodKey = `${group}.${methodName}`;
                    routesByMethod[methodKey] = route;

                    ResponseApi[group][methodName] = (props={}, ajaxProps={}) => Core.buildRequest(methodKey, props, ajaxProps);
                   
                    return;
                }

                hasDefaultRoutes = true;

                routesByMethod[methodName] = route;

                let isDefaultMethods = defaultMethods.includes(methodName);

                Api[methodName] = isDefaultMethods 
                    ? Core[methodName]
                    : (props={}) => Core.buildRequest(methodName, props);
            });

            if ( !hasDefaultRoutes ){
                Api = ResponseApi;
                return;
            }

            Api = $.extend(true, Api, ResponseApi);
        },

        resetState: () => {
            State = Core.getStateData();
        },

        getStateData: () => {
            return {
                fields: [],
                onDuplicateUpdate: [],
                ajax: {},
                where: [],
                or: [],
                andOr: [],
                orderBy: [],
                limit: [],
                page: 1,
                leftJoin: []
            }
        },

        buildRequest: (method, data=State, ajaxProps=State.ajax) => {

            let route = routesByMethod[method];

            if ( !route ) return; /** @todo error */

            let requestConfigs = $.extend(true, {
                url: route.path,
                type: route.type || 'post',
                dataType: "json",
                headers: {},    
                data,
            }, ajaxProps);

            //console.log({route, data})

           // Core.resetState();

            return $.ajax(requestConfigs);
        },

        buildRequestCusomMethod: function(methodName){
            return Core.buildRequest(methodName);
        },

        // Methods defaults

        get: function(){

            let args = arguments;
            let argsCount = args.length;

            if ( !argsCount ) return Core.buildRequest('get');

            let firstArg = args[0];
            let typeFirstArg = $.type(firstArg);
            let isVerboseProps = typeFirstArg == 'object';

            // .get({fields: [], where: []})
            if ( isVerboseProps ){
                Core.setVerboseProps(firstArg);
                return Core.buildRequest('get');
            }

            // .get(["nome", "idade"])
            let isFieldsList = argsCount === 1 && typeFirstArg == 'array';

            let fieldsList = isFieldsList ? args[0] : args;

            for( let i=0; i<fieldsList.length; i++ ){
                let field = fieldsList[i];
                State.fields.push(field);
            }

            return Core.buildRequest('get');
        },

        create: function(){
            let args = Core.getArgs(arguments);
            let argsCount = args.length;

            if ( !argsCount ) return; /** @todo retornar mensagem de erro */
            
            let fields = args[0];

            if ( $.type(fields) !== "object" ) return; /** @todo retornar mensagem de erro */

            if ( fields.onDuplicateUpdate ){
                Api.onDuplicateUpdate(fields.onDuplicateUpdate);
                delete fields.onDuplicateUpdate;
            }

            State.fields = fields;

            return Core.buildRequest('create');
        },

        update: function(){
            let args = Core.getArgs(arguments);
            let argsCount = args.length;

            if ( !argsCount ) return Api; /** @todo retornar mensagem de erro */

            let firstArg = args[0];
            let fields = {};
            let isVerboseProps = $.type(firstArg) == "object";

            if ( argsCount === 2 ){
                fields[firstArg] = args[1];

            } else if ( isVerboseProps ){
                fields = firstArg;

                if ( fields.where ){
                    Api.where(fields.where);
                    delete fields.where;
                }
            }

            State.fields = fields;

            return Core.buildRequest('update');
        },

        delete: function(){
            let args = Core.getArgs(arguments);
            let argsCount = args.length;

            if ( argsCount ){
                Api.where.apply(null, args);
            }

            return Core.buildRequest('delete');
        },

        // Helpers

        getArgs: function(args){
            return Array.prototype.slice.call(args);
        },

        setCondition: (type, arg1, arg2, arg3) => {

            // .where({id})
            if ( $.type(arg1) == "object" ){

                Object.keys(arg1).forEach(key => {
                    let value = arg1[key];
                    State[type].push([key, '=', value]);
                })
                return Api;
            }

            // where("id", "=", 123)
            if ( arg3 ){
                State[type].push([arg1, arg2, arg3]);
                return Api;
            }

            // where(["id", "=", 123],["idade", ">", 18]])
            if ( !arg2 && Array.isArray(arg1) ){
                State[type] = State[type].concat(arg1);
                return Api;
            }

            if ( arg1 && arg2 ){

                if ( typeof arg2 == "string" && arg2.toLowerCase().includes('is') ){

                    // where("id", "is not null")
                    // where("id", "is null")
                    // where("id", "is not empty")
                    State[type].push([arg1, arg2]);
                    return Api;
                }

                // where("id", 123)
                State[type].push([arg1, '=', arg2]);
            }

            return Api;
        },

        setVerboseProps: (props={}) => {

            /* 
            fields: [],
            onDuplicateUpdate: [],
            ajax: {},
            where: [],
            or: [],
            andOr: [],
            orderBy: {
                fields: [],
                sort: 'ASC'
            },
            limit: [],
            page: 1,
            leftJoin: {
                table: '',
                conditions: []
            }
            */

            //console.log('setVerboseProps', props);

            State.fields = props.fields
                ? $.type(props.fields) == 'array' ? props.fields : [props.fields]
                : [];
            
            Api.ajax(props.ajax);
            Api.where(props.where);
            Api.or(props.or);
            Api.onDuplicateUpdate(props.onDuplicateUpdate);
            Api.andOr(props.andOr);
            Api.orderBy(props.orderBy);
            Api.limit(props.limit);
            Api.page(props.page);
            Api.leftJoin(props.leftJoin);
        }
    }

    Core.init();

    return Api;
}   