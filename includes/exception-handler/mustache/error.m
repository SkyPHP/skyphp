<div id="exception-handler">

    <h1>Uncaught Exception: <code>{{type}}</code></h1>

    <h2>Message: <code>{{message}}</code></h2>

    {{#extra}}
    <h3>Info</h3>
        {{>info_list.m}}
    {{/extra}}

    {{#validation_errors}}
    <h3>Validation Errors</h3>
        {{>info_list.m}}
    {{/validation_errors}}

    <h3>Trace</h3>
    <table id="stack-trace">

        <tr>
            <th>Line</th>
            <th>File</th>
            <th>Function/Method</th>
            <th>Arguments</th>
        </tr>

    {{#trace}}

        <tr>
            <td>{{line}}</td>
            <td>{{file}}</td>
            <td>
                {{#class}}
                    {{class}}{{type}}{{function}}
                {{/class}}
                {{^class}}
                    {{function}}
                {{/class}}
            </td>
            <td>
                <ol>
                    {{#args}}
                    <li>
                        <div class="data-content">
                            {{{content}}}
                        </div>
                        <a href="#" class="argument">
                            {{display}}
                        </a>
                    </li>
                    {{/args}}
                </ol>
            </td>
        </tr>

    {{/trace}}

    </table>

</div>
