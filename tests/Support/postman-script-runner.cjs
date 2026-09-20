'use strict';

const fs = require('node:fs');
const vm = require('node:vm');

try {
    const input = JSON.parse(fs.readFileSync(0, 'utf8'));
    if (!Array.isArray(input.scripts) || !input.scripts.every((script) => typeof script === 'string')) {
        throw new TypeError('scripts must be an array of strings.');
    }
    if (input.variables === null || typeof input.variables !== 'object' || Array.isArray(input.variables)) {
        throw new TypeError('variables must be an object.');
    }
    if (input.response === null || typeof input.response !== 'object' || Array.isArray(input.response)) {
        throw new TypeError('response must be an object.');
    }
    if (input.request !== undefined && (input.request === null || typeof input.request !== 'object' || Array.isArray(input.request))) {
        throw new TypeError('request must be an object.');
    }
    if (input.request?.headers !== undefined && (input.request.headers === null || typeof input.request.headers !== 'object' || Array.isArray(input.request.headers))) {
        throw new TypeError('request.headers must be an object.');
    }
    if (input.request?.auth !== undefined && (input.request.auth === null || typeof input.request.auth !== 'object' || Array.isArray(input.request.auth) || typeof input.request.auth.type !== 'string')) {
        throw new TypeError('request.auth must be an object with a string type.');
    }

    const sandbox = Object.create(null);
    sandbox.__postmanInputJson = JSON.stringify(input);
    const context = vm.createContext(sandbox, {
        name: 'postman-script-runner',
        codeGeneration: { strings: false, wasm: false },
    });
    const bootstrap = new vm.Script(`
        'use strict';
        (() => {
            const SafeError = Error;
            const SafeTypeError = TypeError;
            const safeArrayIsArray = Array.isArray;
            const safeHasOwn = Object.hasOwn;
            const safeObjectEntries = Object.entries;
            const safeJsonParse = JSON.parse;
            const safeJsonStringify = JSON.stringify;
            const safeObjectCreate = Object.create;
            const safeObjectKeys = Object.keys;
            const safeString = String;
            const input = safeJsonParse(globalThis.__postmanInputJson);
            delete globalThis.__postmanInputJson;
            const variables = safeObjectCreate(null);
            for (const [key, value] of safeObjectEntries(input.variables)) {
                variables[safeString(key)] = safeString(value);
            }
            const headerValues = safeObjectCreate(null);
            const headerNames = safeObjectCreate(null);
            for (const [name, value] of safeObjectEntries(input.request?.headers ?? {})) {
                const normalized = safeString(name).toLowerCase();
                headerNames[normalized] = safeString(name);
                headerValues[normalized] = safeString(value);
            }
            let nextRequest = null;
            let tests = 0;

            const collectionVariables = Object.freeze({
                get(key) {
                    return safeHasOwn(variables, key) ? variables[key] : null;
                },
                set(key, value) {
                    variables[key] = safeString(value);
                },
            });
            const execution = Object.freeze({
                setNextRequest(name) {
                    nextRequest = name === null ? null : safeString(name);
                },
            });
            const requestHeaders = Object.freeze({
                get(name) {
                    const normalized = safeString(name).toLowerCase();
                    return safeHasOwn(headerValues, normalized) ? headerValues[normalized] : null;
                },
                add(header) {
                    if (header === null || typeof header !== 'object' || safeArrayIsArray(header) || !safeHasOwn(header, 'key')) {
                        throw new SafeTypeError('A Postman header needs a key and value.');
                    }
                    const normalized = safeString(header.key).toLowerCase();
                    headerNames[normalized] = safeString(header.key);
                    headerValues[normalized] = safeString(header.value ?? '');
                },
            });
            const request = Object.freeze({
                headers: requestHeaders,
                auth: Object.freeze({ type: safeString(input.request?.auth?.type ?? '') }),
            });
            const have = Object.freeze({
                status(expected) {
                    if (input.response.status !== expected) {
                        throw new SafeError(\`Expected HTTP \${expected}, received \${input.response.status}.\`);
                    }
                },
            });
            const response = Object.freeze({
                json() {
                    return input.response.body === undefined
                        ? undefined
                        : safeJsonParse(safeJsonStringify(input.response.body));
                },
                to: Object.freeze({ have }),
            });
            const pm = Object.freeze({
                collectionVariables,
                execution,
                request,
                response,
                test(name, callback) {
                    tests++;
                    try {
                        callback();
                    } catch (error) {
                        throw new SafeError(\`\${name}: \${error.message ?? safeString(error)}\`);
                    }
                },
            });

            Object.defineProperty(globalThis, 'pm', { value: pm, enumerable: true });
            Object.defineProperty(globalThis, '__postmanSerializeResult', {
                value: () => {
                    const headers = safeObjectCreate(null);
                    const headerKeys = safeObjectKeys(headerValues);
                    for (let index = 0; index < headerKeys.length; index++) {
                        const normalized = headerKeys[index];
                        headers[headerNames[normalized]] = headerValues[normalized];
                    }
                    const requestResult = safeObjectCreate(null);
                    requestResult.headers = headers;
                    const result = safeObjectCreate(null);
                    result.nextRequest = nextRequest;
                    result.tests = tests;
                    result.variables = variables;
                    result.request = requestResult;

                    return safeJsonStringify(result);
                },
            });
        })();
    `, { filename: 'postman-runner-bootstrap.js' });
    bootstrap.runInContext(context, { timeout: 1000 });

    const script = new vm.Script(`'use strict';\n${input.scripts.join('\n')}`, {
        filename: 'postman-test-script.js',
    });
    script.runInContext(context, { timeout: 1000 });

    const serialize = new vm.Script('__postmanSerializeResult();', {
        filename: 'postman-runner-result.js',
    });
    const result = serialize.runInContext(context, { timeout: 1000 });
    if (typeof result !== 'string') {
        throw new TypeError('Postman runner did not serialize a result.');
    }

    process.stdout.write(result);
} catch (error) {
    process.stderr.write(`${error.stack ?? error.message}\n`);
    process.exitCode = 1;
}
