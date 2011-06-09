<?php

namespace DrSlump\Protobuf\Compiler;

use DrSlump\Protobuf;
use google\protobuf as proto;

class PhpGenerator extends AbstractGenerator
{
    protected $components = array();

    protected function addComponent($ns, $name, $src)
    {
        if (!empty($ns)) {
            $ns = str_replace('\\', '.', $ns);
            $name = $ns . '.' . $name;
        }

        $this->components[$name] = $src;
    }

    public function getNamespace(proto\FileDescriptorProto $proto)
    {
        $namespace = $proto->getPackage();
        $opts = $proto->getOptions();
        if (isset($opts['php.package'])) {
            $namespace = $opts['php.package'];
        }
        if (isset($opts['php.namespace'])) {
            $namespace = $opts['php.namespace'];
        }

        $namespace = trim($namespace, '.\\');
        return str_replace('.', '\\', $namespace);
    }

    public function compileProtoFile(proto\FileDescriptorProto $proto)
    {
        $namespace = $this->getNamespace($proto);

        // Generate Enums
        foreach ($proto->getEnumType() as $enum) {
            $src = $this->compileEnum($enum, $namespace);
            $this->addComponent($namespace, $enum->getName(), $src);
        }

        // Generate Messages
        foreach ($proto->getMessageType() as $msg) {
            $src = $this->compileMessage($msg, $namespace);
            $this->addComponent($namespace, $msg->getName(), $src);
        }

        // Collect extensions
        if ($proto->hasExtension()) {
            foreach ($proto->getExtensionList() as $field) {
                $this->extensions[$field->getExtendee()][] = array($namespace, $field);
            }
        }

        // Dump all extensions found in this proto file
        if (count($this->extensions)):
        $s[]= 'namespace {';
            foreach ($this->extensions as $extendee => $fields) {
                foreach ($fields as $pair) {
                    list($ns, $field) = $pair;
                    $s[] = $this->compileExtension($field, $ns, '  ');
                }
            }
        $s[]= '}';

        $src = implode(PHP_EOL, $s);

        // In multifile mode we output all the extensions in a file named after
        // the proto file, since it's not trivial or even possible in all cases
        // to include the extensions with the extended message file.
        $fname = pathinfo($proto->getName(), PATHINFO_FILENAME);
        $this->addComponent(null, $fname . '-extensions', $src);
        endif;

        $files = array();
        $opts = $proto->getOptions();
        if (empty($opts) || empty($opts['php.multifile'])) {
            $src = '';
            foreach ($this->components as $content) {
                $src .= $content;
            }
            $fname = pathinfo($proto->getName(), PATHINFO_FILENAME);
            $files[] = $this->buildFile($proto, $fname, $src);
        } else {
            foreach ($this->components as $ns => $content) {
                $fname = str_replace('.', '/', $ns);
                $files[] = $this->buildFile($proto, $fname, $content);
            }
        }

        return $files;
    }

    protected function buildFile($proto, $fname, $contents)
    {
        $opts = $proto->hasOptions() ? $proto->getOptions() : array();
        $fname = str_replace('.', '/', $fname);
        $fname .= isset($opts['php.suffix']) ? $opts['php.suffix'] : '.php';

        $file = new \google\protobuf\compiler\CodeGeneratorResponse\File();
        $file->setName($fname);

        $s = array();
        $s[]= "<?php";
        $s[]= "// DO NOT EDIT! Generated by Protobuf for PHP protoc plugin " . Protobuf::VERSION;
        $s[]= "// Source: " . $proto->getName();
        $s[]= "//   Date: " . date('Y-m-d H:i:s');
        $s[]= "";

        $contents = implode(PHP_EOL, $s) . PHP_EOL . $contents;
        $file->setContent($contents);
        return $file;
    }

    public function compileEnum(proto\EnumDescriptorProto $enum, $namespace)
    {
        $s = array();

        $s[]= "namespace $namespace {";
        $s[]= "";
        $s[]= "  class " . $enum->getName() . " {";
        foreach ($enum->getValueList() as $value):
        $s[]= "    const " . $value->getName() . " = " . $value->getNumber() . ";";
        endforeach;
        $s[]= "  }";
        $s[]= "}";
        $s[]= "";

        return implode(PHP_EOL, $s);
    }

    public function compileMessage(proto\DescriptorProto $msg, $namespace)
    {
        $s = array();
        $s[]= "namespace $namespace {";
        $s[]= "";
        $s[]= "  class " . $msg->getName() . " extends \DrSlump\Protobuf\Message {";
        $s[]= "";
        $s[]= '    /** @var \DrSlump\Protobuf\Descriptor */';
        $s[]= '    protected static $__descriptor;';
        $s[]= '    /** @var \Closure[] */';
        $s[]= '    protected static $__extensions = array();';
        $s[]= '';
        $s[]= '    public static function descriptor(\DrSlump\Protobuf\Descriptor $descriptor = NULL)';
        $s[]= '    {';
        $s[]= '      if (NULL !== $descriptor) {';
        $s[]= '        self::$__descriptor = $descriptor;';
        $s[]= '        return self::$__descriptor;';
        $s[]= '      }';
        $s[]= '';
        $s[]= '      if (!self::$__descriptor) {';
        $s[]= '        $descriptor = new \DrSlump\Protobuf\Descriptor(\'\\'.$namespace.'\\'.$msg->getName().'\');';
        $s[]= '';
        foreach ($msg->getField() as $field):
        $s[]=          $this->compileField($field, "        ");
        $s[]= '        $descriptor->addField($f);';
        $s[]= '';
        endforeach;
        $s[]= '        foreach (self::$__extensions as $cb) {';
        $s[]= '          $descriptor->addField($cb(), true);';
        $s[]= '        }';
        $s[]= '';
        $s[]= '        self::$__descriptor = $descriptor;';
        $s[]= '      }';
        $s[]= '';
        $s[]= '      return self::$__descriptor;';
        $s[]= '    }';
        $s[]= '';

        //$s[]= "    protected static \$__exts = array(";
        //foreach ($msg->getExtensionRange() as $range):
        //$s[]= '      array(' . $range->getStart() . ', ' . ($range->getEnd()-1) . '),';
        //endforeach;
        //$s[]= "    );";
        //$s[]= "";

        foreach ($msg->getField() as $field):
        $s[]= $this->generatePublicField($field, "    ");
        endforeach;
        $s[]= "";

        foreach ($msg->getField() as $field):
        $s[]= $this->generateAccessors($field, $namespace . '\\' . $msg->getName(), "    ");
        endforeach;
        $s[]= "  }";
        $s[]= "}";
        $s[]= "";


        // Compute a new namespace with the message name as suffix
        $namespace .= "\\" . $msg->getName();

        // Generate Enums
        if ($msg->hasEnumType()):
        foreach ($msg->getEnumType() as $enum):
        $src = $this->compileEnum($enum, $namespace);
        $this->addComponent($namespace, $enum->getName(), $src);
        endforeach;
        endif;

        // Generate nested messages
        if ($msg->hasNestedType()):
        foreach ($msg->getNestedType() as $msg):
        $src = $this->compileMessage($msg, $namespace);
        $this->addComponent($namespace, $msg->getName(), $src);
        endforeach;
        endif;

        // Collect extensions
        if ($msg->hasExtension()) {
            foreach ($msg->getExtensionList() as $field) {
                $this->_extensions[$field->getExtendee()][] = array($namespace, $field);
            }
        }

        return implode(PHP_EOL, $s);
    }


    public function compileField(proto\FieldDescriptorProto $field, $indent)
    {
        switch ($field->getLabel()) {
        case Protobuf::RULE_REQUIRED:
            $rule = 'required';
            break;
        case Protobuf::RULE_OPTIONAL:
            $rule = 'optional';
            break;
        case Protobuf::RULE_REPEATED:
            $rule = 'repeated';
            break;
        }

        $s[]= "// $rule " . $field->getTypeName() . " " . $field->getName() . " = " . $field->getNumber();
        $s[]= '$f = new \DrSlump\Protobuf\Field();';
        $s[]= '$f->number    = ' . $field->getNumber() . ';';
        $s[]= '$f->name      = "'. $field->getName() . '";';
        $s[]= '$f->type      = ' . $field->getType() . ';';
        $s[]= '$f->rule      = ' . $field->getLabel() . ';';

        if ($field->hasTypeName()):
        $reference = $field->getTypeName();
        if (substr($reference, 0, 1) !== '.') {
            throw new \RuntimeException('Only fully qualified names are supported: ' . $reference);
        }
        $s[]= "\$f->reference = '\\" . $this->normalizeReference($reference) . "';";
        endif;

        if ($field->hasDefaultValue()):
            switch ($field->getType()) {
            case Protobuf::TYPE_BOOL:
                $bool = filter_var($field->getDefaultValue(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $s[]= '$f->default   = ' . ($bool ? 'true' : 'false') . ';';
                break;
            case Protobuf::TYPE_STRING:
                $s[]= '$f->default   = "' . addcslashes($field->getDefaultValue(), '"\\') . '";';
                break;
            case Protobuf::TYPE_ENUM:
                $value = '\\' . $this->normalizeReference($field->getTypeName()) . '::' . $field->getDefaultValue();
                $s[]= '$f->default   = ' . $value . ';';
                break;
            default: // Numbers
                $s[]= '$f->default   = ' . $field->getDefaultValue() . ';';
            }
        endif;

        return $indent . implode(PHP_EOL.$indent, $s);
    }

    public function compileExtension(proto\FieldDescriptorProto $field, $ns, $indent)
    {
        $extendee = $this->normalizeReference($field->getExtendee());

        $name = $field->getName();
        if ($ns) {
            $name = $ns . '.' . $name;
        }
        $name = str_replace('\\', '.', $name);
        $field->setName($name);

        $s[]= "\\$extendee::extension(function(){";
        $s[]= $this->compileField($field, $indent.'  ');
        $s[]= '  return $f;';
        $s[]= "});";

        return $indent . implode(PHP_EOL.$indent, $s);
    }

    public function generatePublicField(proto\FieldDescriptorProto $field, $indent)
    {
        if ($field->getLabel() === Protobuf::RULE_REPEATED) {
            $s[]= "/** @var " . $this->getJavaDocType($field) . "[] */";
            $s[]= 'public $' . $field->getName() . " = array();";
        } else {
            $s[]= "/** @var " . $this->getJavaDocType($field) . " */";
            $default = 'null';
            if ($field->hasDefaultValue()) {
                switch ($field->getType()) {
                case Protobuf::TYPE_BOOL:
                    $default = $field->getDefaultValue() ? 'true' : 'false';
                    break;
                case Protobuf::TYPE_STRING:
                    $default = '"' . addcslashes($field->getDefaultValue(), '"\\') . '"';
                    break;
                case Protobuf::TYPE_ENUM:
                    $default = '\\' . $this->normalizeReference($field->getTypeName()) . '::' . $field->getDefaultValue();
                    break;
                default: // Numbers
                    $default = $field->getDefaultValue();
                }
            }
            $s[]= 'public $' . $field->getName() . ' = ' . $default . ';';
        }
        $s[]= "";

        return $indent . implode(PHP_EOL.$indent, $s);
    }

    public function generateAccessors(proto\FieldDescriptorProto $field, $namespace, $indent)
    {
        $tag = $field->getNumber();
        $name = $field->getName();
        $camel = $this->comp->camelize(ucfirst($name));

        $typehint = '';
        $typedoc = $this->getJavaDocType($field);
        if (0 === strpos($typedoc, '\\')) {
            $typehint = $typedoc;
        }

        // hasXXX
        $s[]= "/**";
        $s[]= " * Check if <$name> has a value";
        $s[]= " *";
        $s[]= " * @return boolean";
        $s[]= " */";
        $s[]= "public function has$camel(){";
        $s[]= "  return \$this->_has($tag);";
        $s[]= "}";
        $s[]= "";

        // clearXXX
        $s[]= "/**";
        $s[]= " * Clear <$name> value";
        $s[]= " *";
        $s[]= " * @return \\$namespace";
        $s[]= " */";
        $s[]= "public function clear$camel(){";
        $s[]= "  return \$this->_clear($tag);";
        $s[]= "}";
        $s[]= "";


        if ($field->getLabel() === Protobuf::RULE_REPEATED):

        // getXXX
        $s[]= "/**";
        $s[]= " * Get <$name> value";
        $s[]= " *";
        $s[]= " * @param int \$idx";
        $s[]= " * @return $typedoc";
        $s[]= " */";
        $s[]= "public function get$camel(\$idx = NULL){";
        $s[]= "  return \$this->_get($tag, \$idx);";
        $s[]= "}";
        $s[]= "";

        // setXXX
        $s[]= "/**";
        $s[]= " * Set <$name> value";
        $s[]= " *";
        $s[]= " * @param $typedoc \$value";
        $s[]= " * @return \\$namespace";
        $s[]= " */";
        $s[]= "public function set$camel($typehint \$value, \$idx = NULL){";
        $s[]= "  return \$this->_set($tag, \$value, \$idx);";
        $s[]= "}";
        $s[]= "";

        $s[]= "/**";
        $s[]= " * Get all elements of <$name>";
        $s[]= " *";
        $s[]= " * @return {$typedoc}[]";
        $s[]= " */";
        $s[]= "public function get{$camel}List(){";
        $s[]= " return \$this->_get($tag);";
        $s[]= "}";
        $s[]= "";

        $s[]= "/**";
        $s[]= " * Add a new element to <$name>";
        $s[]= " *";
        $s[]= " * @param $typedoc \$value";
        $s[]= " * @return \\$namespace";
        $s[]= " */";
        $s[]= "public function add$camel($typehint \$value){";
        $s[]= " return \$this->_add($tag, \$value);";
        $s[]= "}";
        $s[]= "";

        else:

        // getXXX
        $s[]= "/**";
        $s[]= " * Get <$name> value";
        $s[]= " *";
        $s[]= " * @return $typedoc";
        $s[]= " */";
        $s[]= "public function get$camel(){";
        $s[]= "  return \$this->_get($tag);";
        $s[]= "}";
        $s[]= "";

        // setXXX
        $s[]= "/**";
        $s[]= " * Set <$name> value";
        $s[]= " *";
        $s[]= " * @param $typedoc \$value";
        $s[]= " * @return \\$namespace";
        $s[]= " */";
        $s[]= "public function set$camel($typehint \$value){";
        $s[]= "  return \$this->_set($tag, \$value);";
        $s[]= "}";
        $s[]= "";

        endif;

        return $indent . implode(PHP_EOL.$indent, $s);
    }

    public function getJavaDocType(proto\FieldDescriptorProto $field)
    {
        switch ($field->getType()) {
        case Protobuf::TYPE_DOUBLE:
        case Protobuf::TYPE_FLOAT:
            return 'float';
        case Protobuf::TYPE_INT64:
        case Protobuf::TYPE_UINT64:
        case Protobuf::TYPE_INT32:
        case Protobuf::TYPE_FIXED64:
        case Protobuf::TYPE_FIXED32:
        case Protobuf::TYPE_UINT32:
        case Protobuf::TYPE_SFIXED32:
        case Protobuf::TYPE_SFIXED64:
        case Protobuf::TYPE_SINT32:
        case Protobuf::TYPE_SINT64:
            return 'int';
        case Protobuf::TYPE_BOOL:
            return 'boolean';
        case Protobuf::TYPE_STRING:
            return 'string';
        case Protobuf::TYPE_MESSAGE:
            return '\\' . $this->normalizeReference($field->getTypeName());
        case Protobuf::TYPE_BYTES:
            return 'string';
        case Protobuf::TYPE_ENUM:
            return 'int - \\' . $this->normalizeReference($field->getTypeName());

        case Protobuf::TYPE_GROUP:
        default:
            return 'unknown';
        }
    }

    public function normalizeReference($reference)
    {
        // Remove leading dot
        $reference = ltrim($reference, '.');

        if (!$this->comp->hasPackage($reference)) {
            $found = false;
            foreach ($this->comp->getPackages() as $package=>$namespace) {
                if (0 === strpos($reference, $package.'.')) {
                    $reference = $namespace . substr($reference, strlen($package));
                    $found = true;
                }
            }
            if (!$found) {
                $this->comp->warning('Non tracked package name found "' . $reference . '"');
            }
        } else {
            $reference = $this->comp->getPackage($reference);
        }

        return str_replace('.', '\\', $reference);
    }
}
