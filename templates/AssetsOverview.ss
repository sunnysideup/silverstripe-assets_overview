<!doctype html>

<html lang="en">
<head>
  <meta charset="utf-8">
 <% base_tag %>
  <title>Assets Overview</title>
  <style>
  * {
      transition: all 0.2s ease;
      font-family: arial, sans-serif;
      color: rgb(79, 88, 97);
      font-size: 13px;
      font-family: Helvetica Neue,Helvetica,Arial,sans-serif;
  }
  p {margin: 0; padding: 0;}
  .break {
      clear: both;
      border-top: 1px dotted #ddd;
  }
  h1, h2, h3, h4 {
      display: block;
      padding-top: 15px;
      padding-bottom: 0;
      margin-bottom: 0;
  }
  h1 {
      font-size: 20px;
  }
  h2 {
      font-size: 18px;

  }
  h3 {
      font-size: 16px;

  }
  h4 {
      font-size: 14px;
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
  }
  .toc ul {
      padding: 0px;
      margin: 0;
  }
  .toc li {
      list-style: none;
      border-bottom: 1px solid #eee;
      padding: 0px;
      margin: 0;
  }
  .toc li a {
      padding: 4px;
      display: block;
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

  .one-image:hover .one-image-info {
      background-color: rgba(0,0,0, 0.7);
      height: 200px;
      padding: 7px;
      color: #fff;
  }

  .one-image-info u {
      color: #fff;
      font-weight: bold;
      display: block;
      padding-bottom: 7px;
      text-decoration: none;
  }
  .one-image-info strong {
      color: #ddd;
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
  }
  .edit-icon:hover {
      background-color: green;
      color: #fff;
      text-decoration: none!important;
  }
  .edit-icon.error {
      background-color: pink;
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
        /* background-color: #43536D; */
        background-color: #B0BEC7;
        text-align: center;
        padding: 5px;
        text-transform: lowercase;
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
        width: 25%;
        margin: auto;
    }

    .form form .btn-toolbar input {
        background-color: #B0BEC7;
        border-color: #B0BEC7;
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

/* */


  </style>
</head>

<body>
    <div class="toc">
        <div class="padding">
            <% if FilesAsSortedArrayList.count %>
                <% if $FilesAsSortedArrayList.count > 100 %>
                    <p>
                        Too many options to show
                    </p>
                <% else %>
                    <ul>
                        <li><a href="#top">View By Options ...</a><br /></li>
                    <% loop $FilesAsSortedArrayList %>
                        <li><a href="#section-$Number">$SubTitle ($Items.Count)</a></li>
                    <% end_loop %>
                <% end_if %>
                </ul>
            <% end_if %>
        </div>
    </div>

    <div id="top" class="results">
        <div class="padding">
            <p>&laquo; <a href="/admin/assets/">back to CMS</a></p>

            <h1>Totals</h1>
            <p>$TotalFileCount files, current selection uses total of $TotalFileSize in storage</p>

            <div class="form">
                <h3>Group, sort and filter</h3>
                <div class="form-inner">
                    $Form
                </div>
            </div>
            <hr />
            <p>
                <strong>View:</strong>
                <a href="$Link(json)">file list</a>,
                <a href="$Link(jsonfull)">full details</a>
                |
                <strong>Download:</strong>
                <a href="$Link(json)?download=1">file list</a>,
                <a href="$Link(jsonfull)?download=1">full details</a>
            </p>
            <hr />
            <% if FilesAsSortedArrayList.count %>
                <h1>$Title</h1>
                <% if $isThumbList %>
                    <% loop FilesAsSortedArrayList %>
                        <div id="section-$Number" class="break">
                            <h3>$SubTitle</h3>
                            <% loop Items %>
                            <% include OneImage %>
                            <% end_loop %>
                        </div>
                    <% end_loop %>
                <% else %>
                    <hr />
                    <hr />
                    <% loop FilesAsSortedArrayList %>
                        <% loop Items %>
                            $HTML.RAW
                        <% end_loop %>
                    <% end_loop %>
                    <hr />
                    <hr />
                <% end_if %>
            <% else %>
                <p class="message warning">
                    No files found.
                </p>
            <% end_if %>
            </div>
        </div>
    </div>
</body>
</html>
