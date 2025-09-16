<!doctype html>

<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex, nofollow">
 <% base_tag %>
  <title>Assets Overview</title>
  <style>
  body {
      padding-bottom: 90vh;
  }
  * {
      transition: all 0.2s ease;
      font-family: arial, sans-serif;
      color: rgb(79, 88, 97);
      font-family: Helvetica Neue,Helvetica,Arial,sans-serif;
      font-size: 13px;
  }
  p {
      margin: 0; padding: 0.5em 0;}
  .break {
      clear: both;
      border-top: 1px solid #ccc;
  }
  h1, h2, h3, h4, h5 {
      display: block;
      padding-top: 15px;
      padding-bottom: 0;
      margin-bottom: 5px;
  }
  h1, h1 * {
      font-size: 22px;
  }
  h1 strong {
      text-decoration: underline;
  }
  h2, h2 * {
      font-size: 20px;
  }
  h3, h3 * {
      font-size: 18px;
      text-align: center;
      border-bottom: 1px dotted #ccc;

  }
  h4, h5, h4 *, h5 * {
      font-size: 16px;
  }

  a:link, a:visited {
      text-decoration: none;
      color: #304e80;
  }
  a:hover {
      text-decoration: underline;
  }

  .padding {padding: 10px;}
  .results {
      width: 70%;
  }
  li.current {
      font-weight: bold;
  }
  .toc {
      position: fixed;
      width: 30%;
      top: 0;
      bottom: 0;
      right: 0;
      left: 70%;
      background-color: #b0bec7;
      overflow-y: auto;
      font-family: sans-serif;
      border-left: 1px solid #304e80;
      z-index: 99
  }
  .toc ul {
      padding: 0px;
      margin: 0;
  }
  .toc li {
      list-style: none;
      border-bottom: 1px solid #eee;
      margin: 0;
      padding: 2px;
      padding-top: 10px;
      line-height: 1.5em;
  }
  .toc li a {
      display: block;
      padding: 0 2px;
  }
  .toc li a:hover {
      background-color: #304e80;
      color: #fff;
      text-decoration: none;
  }


  .one-image {
      position: relative;
      display: block;
      float: left;
      height: 250px;
      width: auto;
      border: 1px solid #ddd;
      margin: 10px;
      background-color: #eee;
      min-width: 250px;
      overflow: hidden;
      border-radius: 5px;
      position: relative;
  }
  .one-image > a.main-link {
      position: absolute;
      top: 0;
      left:0;
      right: 0;
      bottom: 0;
  }
  .one-image > span {
      position: absolute;
      top: 50%;
      right: 10px;
      left: 10px;
      font-size: 10px;
      font-weight: bold;
      color:#000;
  }
  .one-image > span.main-title {
      position: absolute;
      top: calc(50% + 16px);
  }
  .one-image > span.main-subtitle {
      position: absolute;
      top: calc(50% + 16px);
  }
  .one-image img {
      display: block;
      height: 250px;
      margin-left: auto;
      margin-right: auto;
  }

  .one-image-info {
      background-color: transparent;
      height: 0;
      text-align: left;
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      font-family: sans-serif;
  }
  .one-image-info p {
      color: #fff;
  }

  .one-image:hover .one-image-info {
      background-color: rgba(0,0,0, 0.7);
      height: 190px;
      padding: 7px;
      color: #ddd;
  }
  .one-image-info p {
      padding: 2px 0;
  }
  .one-image-info u {
      color: #fff;
      font-weight: bold;
      display: block;
      text-decoration: none;
  }
  .one-image-info strong {
      color: #fff;
      font-weight: bold;
  }
  .one-image-info a {
       color: #eee!important;
   }
   .one-image-info a:hover {
       text-decoration: none;
       color: #304e80;
   }
  .edit-icon {
      z-index: 99;
      position: absolute;
      top: 10px;
      right: 10px;
      background-color: rgba(0,0,0,0.3);
      display: block;
      width: 30px;
      height: 30px;
      color: #fff;
      border-radius: 50%;
      text-align: center;
      vertical-align: bottom;
      text-decoration: none;
      line-height: 30px;
      font-size: 20px;
      -webkit-transform: scaleX(-1);
      transform: scaleX(-1);
  }
  .edit-icon:hover {
      background-color: green;
      color: #fff;
      text-decoration: none!important;
  }
  .edit-icon.error {
      background-color: red;
  }


    .form {
        margin-top: 10px;
        background-color: #F1F3F6;
    }
    .form * {
        color: #43536D;
        margin: 0;
        padding: 0;
    }

    .form h3 {
        background-color: #B0BEC7;
        text-align: center;
        padding: 5px;
    }
    .form form {
        padding: 10px;
        border: 1px solid #B0BEC7;
    }

    .form form fieldset {
        border: none;
    }



    .form fieldset .field {
        /* background-color: #dbe0e94d */
        background-color: #FFFFFF;
        margin: 10px;
        padding: 10px;
        width: calc(33.33333% - 20px);
        float: left;
        box-sizing: border-box;
        border: 1px solid #B0BEC7;
    }
    label {
        vertical-align: top;
    }
    .form form fieldset .field > label {
        font-weight: bold;
        display: block;
        padding-bottom: 0.7em;
    }

    .form form fieldset .field .middleColumn li {
        list-style: none;
        padding-bottom: 0.3em;
    }

    .form form .btn-toolbar {

    }

    .form form .btn-toolbar input {
        background-color:#B0BEC7;
        border-radius:5px;
        border:1px dotted #18ab29;
        display:block;
        cursor:pointer;
        color:#43536D;
        font-size:17px;
        padding:16px 21px;
        text-decoration:none;
        width: 10em;
        margin: auto;
    }
    .form form .btn-toolbar input:hover {
        background:linear-gradient(to bottom, #5cbf2a 5%, #44c767 100%);
        background-color:#5cbf2a;
        color: #fff;
    }
    .form form .btn-toolbar input:active {
        position:relative;
        top:1px;
    }

    .form select, .form input {
        width: 100%;
        font-weight: bold;
    }

    .form form fieldset .field .middleColumn ul li input {
        width: auto;
    }

    .form form fieldset .field .middleColumn ul li input:checked + label{
        font-weight: bolder;
    }

    .form #Form_index_extensions_Holder li {
        width: 50%;
        float: left;
    }

/* */


  </style>
</head>

<body>
    <div class="toc">
        <div class="padding">
            <% if FilesAsSortedArrayList.count %>
                <h4>Find on this page</h4>
                <p>
                    <a href="#top">« update filter</a>
                </p>
                <ul>
                <% loop $FilesAsSortedArrayList %>
                    <li><a href="#section-$Number">$SubTitle ($Items.Count)</a></li>
                <% end_loop %>
                </ul>
            <% end_if %>

            <h4>Additional options</h4>
            <ul>
                <li>
                    <a href="$Link?flush=al">Reset Cache</a>
                </li>


                <li>
                    <strong>View JSON:</strong>
                    <a href="$Link(json)">file list</a>
                    <a href="$Link(jsonfull)">full details</a>
                </li>

                <li>
                    <strong>Download JSON:</strong>
                    <a href="$Link(json)?download=1">file list</a>
                    <a href="$Link(jsonfull)?download=1">full details</a>
                </li>
                <li>
                    <strong>Sync:</strong>
                    <a href="$Link(sync)" onclick="return confirm('Danger - Danger - Have you made a backup of files and database?')">update DB based on files on disk</a>
                    <a href="$Link(addtodb)" onclick="return confirm('Danger - Danger - Have you made a backup of files and database?')">add to DB only</a>
                    <a href="$Link(removefromdb)" onclick="return confirm('Danger - Danger - Have you made a backup of files and database?')">remove from DB only</a>
                </li>
            </ul>

            <h4>Limitations</h4>
            <ul>
                <li>Publicly accessible files only</li>
                <li>Does not take into account "canView"</li>
                <li>Only works with local file system</li>
            </ul>
        </div>
    </div>

    <div id="top" class="results">
        <div class="padding">
            <p>
            &laquo; <a href="/admin/assets/">back to CMS</a><br />
            &laquo; <a href="/admin/reports/show/Sunnysideup-AssetsOverview-Reports-ImagesPerFieldReport/">Review Images per Field</a>
            </p>

            <div class="form">
                <h3>Sort and Filter</h3>
                <div class="form-inner">
                    $Form
                </div>
            </div>

            <% if $FilesAsSortedArrayList.count %>
                <h1>$Title.RAW</h1>
                <p>$Subtitle.RAW</p>

                <% if $Displayer = 'thumbs' %>
                    <% loop $FilesAsSortedArrayList %>
                        <div id="section-$Number" class="break">
                            <h3>$SubTitle</h3>
                            <% loop $Items %>
                                <% include OneFile %>
                            <% end_loop %>
                        </div>
                    <% end_loop %>
                <% end_if %>

                <% if $Displayer = 'rawlist' %>
                    <% loop $FilesAsSortedArrayList %>
                        <ul id="section-$Number" class="break">
                        <% loop $Items %>
                            <li>
                                <h5><a href="$PathFromPublicRoot">$Path</a></h5>
                            </li>
                        <% end_loop %>
                        </ul>
                    <% end_loop %>
                <% end_if %>

                <% if $Displayer = 'rawlistfull' %>
                    <% loop $FilesAsSortedArrayList %>
                        <ul id="section-$Number" class="break">
                        <% loop $Items %>
                            <li>
                                <h5><a href="$PathFromPublicRoot">$Path</a></h5>
                                <ul>
                                <% loop $FullFields %>
                                    <li><strong>$Key:</strong> $Value</li>
                                <% end_loop %>
                                </ul>
                            </li>
                        <% end_loop %>
                        </ul>
                    <% end_loop %>
                <% end_if %>

            <% else %>
                <p class="message warning">
                    No files found.
                </p>
            <% end_if %>
            </div>
        </div>
    </div>
    <script>
        window.addEventListener(
            "DOMContentLoaded",
            function(){
                const els = document.querySelectorAll('input, select');
                for (let i=0; i < els.length; i++) {
                    els[i].setAttribute("onchange", "this.form.submit(); this.form.innerHTML = 'loading ...'; document.querySelector('.results').style.opacity = '0.5'");
                }
                const btn = document.querySelector('#Form_index_action_index');
                btn.style.display = 'none';
                // const form = document.querySelector('form');
                // form.addEventListener(
                //     "submit",
                //     (event) => {
                //         console.log('We do get here when both a submit button is clicked and the image')
                //     }
                // );
            }
        );


    </script>
</body>
</html>
