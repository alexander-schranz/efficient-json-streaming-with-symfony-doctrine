async function* loadLineByLine(url) {
    const utf8Decoder = new TextDecoder('utf-8');
    const response = await fetch(url);
    const reader = response.body.getReader();
    let { value: chunk, done: readerDone } = await reader.read();
    chunk = chunk ? utf8Decoder.decode(chunk) : '';

    const re = /\n|\r|\r\n/gm;
    let startIndex = 0;
    let result;

    for (;;) {
        let result = re.exec(chunk);
        if (!result) {
            if (readerDone) {
                break;
            }
            let remainder = chunk.substr(startIndex);
            ({ value: chunk, done: readerDone } = await reader.read());
            chunk = remainder + (chunk ? utf8Decoder.decode(chunk) : '');
            startIndex = re.lastIndex = 0;
            continue;
        }

        yield chunk.substring(startIndex, result.index);
        startIndex = re.lastIndex;
    }

    if (startIndex < chunk.length) {
        // last line didn't end in a newline char
        yield chunk.substr(startIndex);
    }
}

async function run(itemCallback, contentCallback) {
    let content = '';

    for await (let line of loadLineByLine('articles.json')) {
        try {
            const object = JSON.parse(line.replace(/\,$/, ''));

            itemCallback(object);
        } catch (e) {
            content += line;

            continue;
        }
    }

    contentCallback(content);
}

const list = document.getElementById('list');
const loader = document.getElementById('loading');
let counter = 0;

run((object) => {
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td>' + object.id + '</td>'
        + '<td>' + object.title + '</td>'
        + '<td>' + object.description + '</td>';

    list.append(tr);

    ++counter;
    loader.innerText = 'Loaded ' + counter;
}, (content) => {
    loader.innerText = 'Loaded: ' + content;
});
